#!/usr/bin/env python3
"""
Simple Self-Distillation (SSD) for code generation models.

Based on: "Embarrassingly Simple Self-Distillation Improves Code Generation"
(Zhang et al., 2025) — https://arxiv.org/abs/2604.01193

The model generates solutions at elevated temperature, then re-trains on its
own unverified outputs via standard SFT. This reshapes the token distribution:
sharpening "lock" positions (where one token dominates) while preserving
diversity at "fork" positions (where multiple continuations are viable).

Usage:
  python3 ssd.py --model output/merged_model --dataset data/dataset.jsonl --output-dir output
  python3 ssd.py --model output/merged_model --dataset data/dataset.jsonl --output-dir output --temperature 1.5 --rounds 2

Requires: unsloth, transformers, trl
"""

# Prevent torch.compile OOM on 24GB GPUs
import os
os.environ["TORCHDYNAMO_DISABLE"] = "1"
os.environ.setdefault("PYTORCH_CUDA_ALLOC_CONF", "expandable_segments:True")

import argparse
import json
import sys
import time
from pathlib import Path


def load_prompts_from_dataset(dataset_path: str, max_prompts: int = 500) -> list[dict]:
    """Extract user prompts from an existing SFT dataset for self-distillation.

    Each entry in the dataset has {"messages": [{"role": "system", ...}, {"role": "user", ...}, ...]}.
    We extract system+user messages as prompts for the model to re-generate responses.
    """
    prompts = []
    with open(dataset_path) as f:
        for line in f:
            line = line.strip()
            if not line:
                continue
            entry = json.loads(line)
            messages = entry.get("messages", [])
            system_msg = ""
            user_msg = ""
            for msg in messages:
                if msg["role"] == "system":
                    system_msg = msg["content"]
                elif msg["role"] == "user":
                    user_msg = msg["content"]
                    break  # Take first user message only
            if user_msg:
                prompts.append({"system": system_msg, "user": user_msg})
    # Deduplicate by user message
    seen = set()
    unique = []
    for p in prompts:
        if p["user"] not in seen:
            seen.add(p["user"])
            unique.append(p)
    # Limit and return
    if len(unique) > max_prompts:
        import random
        random.seed(42)
        random.shuffle(unique)
        unique = unique[:max_prompts]
    print(f"Extracted {len(unique)} unique prompts from {dataset_path}")
    return unique


def generate_ssd_data(
    model,
    tokenizer,
    prompts: list[dict],
    temperature: float,
    max_new_tokens: int = 2048,
    top_p: float = 0.95,
) -> list[dict]:
    """Generate self-distilled training data by sampling from the model at elevated temperature.

    Per the SSD paper, we sample single completions per prompt at T_train > 1.0.
    No filtering or verification — the signal comes from distribution reshaping,
    not from learning correct solutions.
    """
    import torch

    ssd_data = []
    total = len(prompts)
    start = time.time()

    # Use the text tokenizer for chat template (handles multimodal models like Qwen3.5)
    text_tokenizer = getattr(tokenizer, 'tokenizer', tokenizer)

    for i, prompt in enumerate(prompts):
        messages = []
        if prompt["system"]:
            messages.append({"role": "system", "content": prompt["system"]})
        messages.append({"role": "user", "content": prompt["user"]})

        # Apply chat template
        input_text = text_tokenizer.apply_chat_template(
            messages,
            tokenize=False,
            add_generation_prompt=True,
        )

        inputs = text_tokenizer(
            input_text,
            return_tensors="pt",
            truncation=True,
            max_length=2048,
        ).to(model.device)

        with torch.no_grad():
            outputs = model.generate(
                **inputs,
                max_new_tokens=max_new_tokens,
                temperature=temperature,
                top_p=top_p,
                do_sample=True,
                pad_token_id=text_tokenizer.eos_token_id,
            )

        # Decode only the generated tokens (skip the input)
        input_len = inputs["input_ids"].shape[1]
        generated = outputs[0][input_len:]
        response = text_tokenizer.decode(generated, skip_special_tokens=True).strip()

        if response:  # Keep even imperfect responses — per the paper this still helps
            training_entry = {"messages": list(messages) + [{"role": "assistant", "content": response}]}
            ssd_data.append(training_entry)

        elapsed = time.time() - start
        rate = (i + 1) / elapsed if elapsed > 0 else 0
        eta = (total - i - 1) / rate if rate > 0 else 0
        print(
            f"\rSSD generation: {i + 1}/{total} | "
            f"{len(ssd_data)} valid | "
            f"{rate:.1f} prompts/s | "
            f"ETA {eta:.0f}s",
            end="", flush=True,
        )

    print()
    return ssd_data


def run_ssd_sft(
    model,
    tokenizer,
    ssd_data: list[dict],
    output_dir: Path,
    lr: float = 5e-5,
    epochs: int = 1,
    batch_size: int = 2,
    max_seq_length: int = 4096,
    gradient_accumulation_steps: int = 4,
) -> float:
    """Run SFT on self-distilled data. Returns training loss."""
    from trl import SFTTrainer
    from transformers import TrainingArguments
    from datasets import Dataset

    text_tokenizer = getattr(tokenizer, 'tokenizer', tokenizer)

    dataset = Dataset.from_list(ssd_data)

    def tokenize_chat(example):
        text = text_tokenizer.apply_chat_template(
            example["messages"],
            tokenize=False,
            add_generation_prompt=False,
        )
        tokens = text_tokenizer(
            text,
            truncation=True,
            max_length=max_seq_length,
            padding=False,
        )
        tokens["labels"] = tokens["input_ids"].copy()
        return tokens

    dataset = dataset.map(tokenize_chat, remove_columns=dataset.column_names)
    split = dataset.train_test_split(test_size=0.05, seed=42)
    print(f"SSD SFT — Train: {len(split['train'])}, Eval: {len(split['test'])}")

    ssd_checkpoint_dir = str(output_dir / "ssd_checkpoints")

    training_args = TrainingArguments(
        per_device_train_batch_size=batch_size,
        gradient_accumulation_steps=gradient_accumulation_steps,
        warmup_steps=5,
        num_train_epochs=epochs,
        learning_rate=lr,
        bf16=True,
        logging_steps=10,
        eval_strategy="steps",
        eval_steps=50,
        save_strategy="steps",
        save_steps=100,
        output_dir=ssd_checkpoint_dir,
        save_total_limit=2,
        report_to="none",
        optim="adamw_8bit",
        seed=42,
    )

    trainer = SFTTrainer(
        model=model,
        processing_class=text_tokenizer,
        train_dataset=split["train"],
        eval_dataset=split["test"],
        max_seq_length=max_seq_length,
        packing=False,  # Don't pack SSD data — preserve distribution structure
        args=training_args,
    )

    stats = trainer.train()
    print(f"  SSD SFT loss: {stats.training_loss:.4f}")
    print(f"  Runtime: {stats.metrics['train_runtime']:.0f}s")

    return stats.training_loss


def main():
    parser = argparse.ArgumentParser(
        description="Simple Self-Distillation (SSD) — improve code models by training on own outputs"
    )
    parser.add_argument("--model", required=True, help="Path to merged model directory (from train.py SFT stage)")
    parser.add_argument("--dataset", required=True, help="Path to original SFT dataset.jsonl (for extracting prompts)")
    parser.add_argument("--output-dir", required=True, help="Output directory for SSD artifacts")
    parser.add_argument("--temperature", type=float, default=1.5,
                        help="Sampling temperature for self-distillation (paper recommends 1.2-2.0, default: 1.5)")
    parser.add_argument("--rounds", type=int, default=1,
                        help="Number of SSD rounds (default: 1, diminishing returns after 2)")
    parser.add_argument("--max-prompts", type=int, default=500,
                        help="Max prompts to sample from dataset (default: 500)")
    parser.add_argument("--lr", type=float, default=5e-5,
                        help="Learning rate for SSD SFT (lower than initial SFT, default: 5e-5)")
    parser.add_argument("--epochs", type=int, default=1,
                        help="SSD SFT epochs per round (default: 1)")
    parser.add_argument("--batch-size", type=int, default=2)
    parser.add_argument("--max-seq-length", type=int, default=4096)
    parser.add_argument("--lora-rank", type=int, default=64)
    parser.add_argument("--gradient-accumulation-steps", type=int, default=4)
    parser.add_argument("--top-p", type=float, default=0.95,
                        help="Top-p truncation for generation (default: 0.95)")
    parser.add_argument("--no-packing", action="store_true")
    args = parser.parse_args()

    model_path = Path(args.model)
    dataset_path = Path(args.dataset)
    output_dir = Path(args.output_dir)

    if not model_path.exists():
        print(f"Model not found: {model_path}")
        sys.exit(1)
    if not dataset_path.exists():
        print(f"Dataset not found: {dataset_path}")
        sys.exit(1)

    output_dir.mkdir(parents=True, exist_ok=True)

    # Load model
    from unsloth import FastModel

    print(f"\nLoading model from {model_path}...")
    model, tokenizer = FastModel.from_pretrained(
        model_name=str(model_path),
        max_seq_length=args.max_seq_length,
        dtype=None,
        load_in_4bit=True,
    )

    # Apply LoRA for SSD training (fresh adapter on top of the SFT-merged model)
    model = FastModel.get_peft_model(
        model,
        r=args.lora_rank,
        target_modules=[
            "q_proj", "k_proj", "v_proj", "o_proj",
            "gate_proj", "up_proj", "down_proj",
        ],
        lora_alpha=args.lora_rank,
        lora_dropout=0,
        bias="none",
        use_gradient_checkpointing="unsloth",
    )

    trainable = sum(p.numel() for p in model.parameters() if p.requires_grad)
    total = sum(p.numel() for p in model.parameters())
    print(f"Trainable: {trainable:,} / {total:,} ({100 * trainable / total:.2f}%)")

    # Extract prompts from existing dataset
    prompts = load_prompts_from_dataset(str(dataset_path), max_prompts=args.max_prompts)

    if not prompts:
        print("No prompts extracted from dataset — nothing to self-distill")
        sys.exit(1)

    final_loss = 0.0

    for round_num in range(1, args.rounds + 1):
        print(f"\n{'=' * 60}")
        print(f"SSD Round {round_num}/{args.rounds} (T={args.temperature}, top_p={args.top_p})")
        print(f"{'=' * 60}")

        # Stage A: Generate self-distilled data at elevated temperature
        print(f"\nGenerating self-distilled data at T={args.temperature}...")
        ssd_data = generate_ssd_data(
            model, tokenizer, prompts,
            temperature=args.temperature,
            top_p=args.top_p,
        )

        if len(ssd_data) < 5:
            print(f"Only {len(ssd_data)} SSD examples generated — skipping round")
            continue

        # Save SSD data for inspection
        ssd_data_path = output_dir / f"ssd_data_round{round_num}.jsonl"
        with open(ssd_data_path, "w") as f:
            for entry in ssd_data:
                f.write(json.dumps(entry) + "\n")
        print(f"Saved {len(ssd_data)} SSD examples to {ssd_data_path}")

        # Stage B: SFT on self-distilled data
        print(f"\nRunning SSD SFT (round {round_num})...")
        final_loss = run_ssd_sft(
            model, tokenizer, ssd_data, output_dir,
            lr=args.lr,
            epochs=args.epochs,
            batch_size=args.batch_size,
            max_seq_length=args.max_seq_length,
            gradient_accumulation_steps=args.gradient_accumulation_steps,
        )

    # Save SSD LoRA adapter
    ssd_lora_path = str(output_dir / "lora_adapter_ssd")
    model.save_pretrained(ssd_lora_path)
    tokenizer.save_pretrained(ssd_lora_path)
    print(f"\nSSD LoRA adapter saved to: {ssd_lora_path}")

    # Merge into final model
    print("\nMerging SSD model...")
    merged_path = str(output_dir / "merged_model")
    model.save_pretrained_merged(
        merged_path,
        tokenizer,
        save_method="merged_16bit",
    )
    print(f"SSD merged model saved to: {merged_path}")

    # Machine-readable output for PHP toolchain
    print(f"\nSSD_LOSS: {final_loss:.6f}")
    print(f"SSD_ROUNDS: {args.rounds}")
    print(f"SSD_TEMPERATURE: {args.temperature}")
    print(f"SSD_EXAMPLES: {len(ssd_data) if 'ssd_data' in dir() else 0}")


if __name__ == "__main__":
    main()
