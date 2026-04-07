#!/usr/bin/env python3
"""
Fine-tune Qwen2.5-Coder with QLoRA using Unsloth.

Usage:
  python3 train.py --dataset data/dataset.jsonl
  python3 train.py --dataset data/dataset.jsonl --model-id unsloth/Qwen2.5-Coder-7B-Instruct
  python3 train.py --dataset data/dataset.jsonl --base-model 7b --epochs 1

Requirements (installed automatically):
  pip install unsloth
"""

# MUST be set before any torch imports — prevents torch.compile OOM on 24GB GPUs
import os
os.environ["TORCHDYNAMO_DISABLE"] = "1"
os.environ.setdefault("PYTORCH_CUDA_ALLOC_CONF", "expandable_segments:True")

import argparse
import json
import sys
from pathlib import Path

MODELS = {
    "0.5b": "unsloth/Qwen2.5-Coder-0.5B-Instruct",
    "1.5b": "unsloth/Qwen2.5-Coder-1.5B-Instruct",
    "3b": "unsloth/Qwen2.5-Coder-3B-Instruct",
    "7b": "unsloth/Qwen2.5-Coder-7B-Instruct",
    "7b-tools": "unsloth/Qwen2.5-7B-Instruct",
    "9b": "unsloth/Qwen3.5-9B",
    "14b": "unsloth/Qwen2.5-Coder-14B-Instruct",
    "lfm2-tool": "LiquidAI/LFM2-1.2B-Tool",
    "lfm2": "LiquidAI/LFM2-1.2B",
}


def install_deps():
    try:
        import unsloth  # noqa: F401
        return True
    except ImportError:
        print("Installing unsloth + dependencies (this takes a few minutes)...")
        os.system(f'{sys.executable} -m pip install "unsloth[colab-new] @ git+https://github.com/unslothai/unsloth.git"')
        os.system(f"{sys.executable} -m pip install xformers trl peft accelerate bitsandbytes torchvision")
        return True


def load_dataset(path: str) -> list[dict]:
    examples = []
    with open(path) as f:
        for line in f:
            line = line.strip()
            if line:
                examples.append(json.loads(line))
    print(f"Loaded {len(examples)} training examples")
    return examples


def load_dpo_dataset(path: str) -> list[dict]:
    """Load DPO preference pairs from JSONL (harvest.py output)."""
    pairs = []
    with open(path) as f:
        for line in f:
            line = line.strip()
            if line:
                pairs.append(json.loads(line))
    print(f"Loaded {len(pairs)} DPO preference pairs")
    return pairs


def run_dpo_stage(model, tokenizer, text_tokenizer, dpo_data, args, output_dir):
    """Run DPO training stage on top of SFT model."""
    from trl import DPOTrainer, DPOConfig
    from datasets import Dataset

    print(f"\n{'=' * 60}")
    print("Stage 2: DPO (Direct Preference Optimization)")
    print(f"{'=' * 60}")

    dataset = Dataset.from_list(dpo_data)
    split = dataset.train_test_split(test_size=0.05, seed=42)
    print(f"DPO Train: {len(split['train'])}, Eval: {len(split['test'])}")

    dpo_config = DPOConfig(
        per_device_train_batch_size=1,
        gradient_accumulation_steps=args.gradient_accumulation_steps * 2,
        warmup_steps=5,
        num_train_epochs=args.dpo_epochs,
        learning_rate=args.dpo_lr,
        bf16=True,
        logging_steps=5,
        eval_strategy="steps",
        eval_steps=25,
        save_strategy="steps",
        save_steps=50,
        output_dir=str(output_dir / "dpo_checkpoints"),
        save_total_limit=2,
        report_to="none",
        optim="adamw_8bit",
        seed=42,
        beta=args.dpo_beta,
        max_length=args.max_seq_length,
        max_prompt_length=args.max_seq_length // 2,
    )

    trainer = DPOTrainer(
        model=model,
        ref_model=None,  # Uses implicit reference (LoRA base)
        processing_class=text_tokenizer,
        train_dataset=split["train"],
        eval_dataset=split["test"],
        args=dpo_config,
    )

    print("\nStarting DPO training...")
    stats = trainer.train()
    print(f"\nDPO training complete!")
    print(f"  Loss: {stats.training_loss:.4f}")
    print(f"  Runtime: {stats.metrics['train_runtime']:.0f}s")
    print(f"DPO_LOSS: {stats.training_loss:.6f}")

    return stats


def main():
    parser = argparse.ArgumentParser(description="QLoRA fine-tuning with Unsloth (SFT + optional DPO)")
    parser.add_argument("--dataset", required=True, help="Path to SFT dataset.jsonl")
    parser.add_argument("--dpo-dataset", help="Path to DPO preference pairs JSONL (from harvest.py)")
    parser.add_argument("--output-dir", default="output", help="Output directory")
    parser.add_argument("--model-id", help="Full HuggingFace model ID")
    parser.add_argument("--base-model", choices=list(MODELS.keys()), default="7b", help="Model size shorthand")
    parser.add_argument("--epochs", type=int, default=3, help="SFT epochs")
    parser.add_argument("--dpo-epochs", type=int, default=1, help="DPO epochs")
    parser.add_argument("--dpo-lr", type=float, default=5e-5, help="DPO learning rate (lower than SFT)")
    parser.add_argument("--dpo-beta", type=float, default=0.1, help="DPO beta (KL penalty weight)")
    parser.add_argument("--batch-size", type=int, default=2)
    parser.add_argument("--lr", type=float, default=2e-4)
    parser.add_argument("--lora-rank", type=int, default=64)
    parser.add_argument("--max-seq-length", type=int, default=4096)
    parser.add_argument("--no-packing", action="store_true", help="Disable sequence packing (saves VRAM for large models)")
    parser.add_argument("--gradient-accumulation-steps", type=int, default=4)
    parser.add_argument("--sft-only", action="store_true", help="Skip DPO even if --dpo-dataset is provided")
    parser.add_argument("--resume-checkpoint", help="Resume SFT training from a checkpoint directory")
    parser.add_argument("--ssd", action="store_true", help="Run Simple Self-Distillation after SFT+DPO (paper: arxiv.org/abs/2604.01193)")
    parser.add_argument("--ssd-temperature", type=float, default=1.5, help="SSD sampling temperature (default: 1.5, paper recommends 1.2-2.0)")
    parser.add_argument("--ssd-rounds", type=int, default=1, help="SSD rounds (default: 1, diminishing returns after 2)")
    parser.add_argument("--ssd-max-prompts", type=int, default=500, help="Max prompts for SSD generation (default: 500)")
    parser.add_argument("--ssd-lr", type=float, default=5e-5, help="SSD learning rate (default: 5e-5)")
    args = parser.parse_args()

    dataset_path = Path(args.dataset)
    if not dataset_path.exists():
        print(f"Dataset not found: {args.dataset}")
        sys.exit(1)

    install_deps()

    from unsloth import FastModel
    from trl import SFTTrainer
    from transformers import TrainingArguments
    from datasets import Dataset

    model_name = args.model_id or MODELS[args.base_model]
    print(f"\nLoading {model_name}...")

    model, tokenizer = FastModel.from_pretrained(
        model_name=model_name,
        max_seq_length=args.max_seq_length,
        dtype=None,
        load_in_4bit=True,
    )

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

    raw_data = load_dataset(str(dataset_path))

    # Pre-tokenize: apply chat template to convert messages → text,
    # then tokenize so SFTTrainer gets input_ids directly
    dataset = Dataset.from_list(raw_data)

    # For multimodal models (e.g. Qwen3.5-9B), the "tokenizer" is a Processor
    # with an image_processor. Extract the inner text tokenizer for text-only training.
    text_tokenizer = getattr(tokenizer, 'tokenizer', tokenizer)

    def tokenize_chat(example):
        text = text_tokenizer.apply_chat_template(
            example["messages"],
            tokenize=False,
            add_generation_prompt=False,
        )
        tokens = text_tokenizer(
            text,
            truncation=True,
            max_length=args.max_seq_length,
            padding=False,
        )
        tokens["labels"] = tokens["input_ids"].copy()
        return tokens

    dataset = dataset.map(tokenize_chat, remove_columns=dataset.column_names)

    split = dataset.train_test_split(test_size=0.05, seed=42)
    print(f"Train: {len(split['train'])}, Eval: {len(split['test'])}")

    output_dir = Path(args.output_dir)
    output_dir.mkdir(parents=True, exist_ok=True)

    training_args = TrainingArguments(
        per_device_train_batch_size=args.batch_size,
        gradient_accumulation_steps=args.gradient_accumulation_steps,
        warmup_steps=10,
        num_train_epochs=args.epochs,
        learning_rate=args.lr,
        bf16=True,
        logging_steps=10,
        eval_strategy="steps",
        eval_steps=50,
        save_strategy="steps",
        save_steps=100,
        output_dir=str(output_dir / "checkpoints"),
        save_total_limit=2,
        report_to="none",
        optim="adamw_8bit",
        seed=42,
    )

    use_packing = not args.no_packing
    trainer = SFTTrainer(
        model=model,
        processing_class=text_tokenizer,
        train_dataset=split["train"],
        eval_dataset=split["test"],
        max_seq_length=args.max_seq_length,
        packing=use_packing,
        args=training_args,
    )

    print("\n" + "=" * 60)
    print("Stage 1: SFT (Supervised Fine-Tuning)")
    print("=" * 60)

    resume_from = args.resume_checkpoint if hasattr(args, 'resume_checkpoint') else None
    if resume_from:
        print(f"\nResuming SFT training from {resume_from}...")
    else:
        print("\nStarting SFT training...")
    stats = trainer.train(resume_from_checkpoint=resume_from)
    print(f"\nSFT training complete!")
    print(f"  Loss: {stats.training_loss:.4f}")
    print(f"  Runtime: {stats.metrics['train_runtime']:.0f}s")
    print(f"TRAINING_LOSS: {stats.training_loss:.6f}")

    # Save LoRA adapter
    lora_path = str(output_dir / "lora_adapter")
    model.save_pretrained(lora_path)
    tokenizer.save_pretrained(lora_path)
    print(f"\nLoRA adapter saved to: {lora_path}")

    # Always save the SFT-only merged model (for comparison)
    print("\nMerging SFT LoRA into base model...")
    sft_merged_path = str(output_dir / "merged_model_sft")
    model.save_pretrained_merged(
        sft_merged_path,
        tokenizer,
        save_method="merged_16bit",
    )
    print(f"SFT merged model saved to: {sft_merged_path}")

    # DPO stage (if preference data provided)
    dpo_path = args.dpo_dataset
    if dpo_path and not args.sft_only:
        dpo_dataset_path = Path(dpo_path)
        if not dpo_dataset_path.exists():
            print(f"\nDPO dataset not found: {dpo_path} — skipping DPO stage")
        else:
            dpo_data = load_dpo_dataset(str(dpo_dataset_path))
            if len(dpo_data) < 5:
                print(f"\nOnly {len(dpo_data)} DPO pairs — need at least 5. Skipping DPO stage.")
            else:
                run_dpo_stage(model, tokenizer, text_tokenizer, dpo_data, args, output_dir)

                # Save DPO LoRA adapter
                dpo_lora_path = str(output_dir / "lora_adapter_dpo")
                model.save_pretrained(dpo_lora_path)
                tokenizer.save_pretrained(dpo_lora_path)
                print(f"\nDPO LoRA adapter saved to: {dpo_lora_path}")

    # Save intermediate merged model (SFT+DPO, before SSD)
    print("\nMerging intermediate model...")
    merged_path = str(output_dir / "merged_model")
    model.save_pretrained_merged(
        merged_path,
        tokenizer,
        save_method="merged_16bit",
    )
    print(f"Intermediate merged model saved to: {merged_path}")

    # SSD stage (Simple Self-Distillation)
    if args.ssd:
        print(f"\n{'=' * 60}")
        print(f"Stage 3: SSD (Simple Self-Distillation)")
        print(f"  Temperature: {args.ssd_temperature}, Rounds: {args.ssd_rounds}")
        print(f"  Paper: https://arxiv.org/abs/2604.01193")
        print(f"{'=' * 60}")

        ssd_script = str(Path(__file__).parent / "ssd.py")
        ssd_cmd = [
            sys.executable, ssd_script,
            "--model", merged_path,
            "--dataset", str(dataset_path),
            "--output-dir", str(output_dir),
            "--temperature", str(args.ssd_temperature),
            "--rounds", str(args.ssd_rounds),
            "--max-prompts", str(args.ssd_max_prompts),
            "--lr", str(args.ssd_lr),
            "--batch-size", str(args.batch_size),
            "--max-seq-length", str(args.max_seq_length),
            "--lora-rank", str(args.lora_rank),
            "--gradient-accumulation-steps", str(args.gradient_accumulation_steps),
        ]
        if args.no_packing:
            ssd_cmd.append("--no-packing")

        import subprocess
        print(f"\nLaunching SSD subprocess...")
        result = subprocess.run(ssd_cmd, capture_output=False)
        if result.returncode != 0:
            print(f"\nSSD stage failed (exit {result.returncode}) — final model is SFT+DPO only")
        else:
            # SSD overwrites merged_model in output_dir
            print(f"SSD complete — merged model updated at: {merged_path}")
    else:
        print(f"Final merged model saved to: {merged_path}")

    print(f"\nNext step: Run export.sh to convert to GGUF + Ollama")
    print(f"\nTo compare stages, export each:")
    print(f"  ./export.sh mymodel-sft {sft_merged_path} gguf-sft/")
    print(f"  ./export.sh mymodel {merged_path} gguf/")


if __name__ == "__main__":
    main()
