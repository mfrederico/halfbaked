#!/usr/bin/env python3
"""
Harvest DPO preference pairs from builder.db execution data.

Extracts chosen/rejected pairs from:
  1. Multi-attempt subtasks: early failed attempts (rejected) vs final accepted (chosen)
  2. Cross-subtask comparison: completed vs failed subtasks with similar prompts
  3. Synthetic generation: run the SFT model N times per prompt, score outputs

Usage:
  python3 harvest.py --db data/builder.db --output data/dpo_pairs.jsonl
  python3 harvest.py --db data/builder.db --output data/dpo_pairs.jsonl --synthetic --model php-expert --ollama-host 127.0.0.1
  python3 harvest.py --sft-dataset data/dataset.jsonl --output data/dpo_pairs.jsonl --synthetic --model php-expert

Requirements:
  pip install anthropic  (only for synthetic scoring)
"""

import argparse
import json
import os
import sqlite3
import sys
from pathlib import Path


def connect_db(db_path: str) -> sqlite3.Connection:
    conn = sqlite3.connect(db_path)
    conn.row_factory = sqlite3.Row
    # Run migrations for new columns (matches BuildRegistry.php)
    for col, default in [("prompt", "NULL"), ("attempts_log", "NULL")]:
        try:
            conn.execute(f"ALTER TABLE build_subtasks ADD COLUMN {col} TEXT DEFAULT {default}")
        except sqlite3.OperationalError:
            pass  # Column already exists
    return conn


def harvest_from_attempts(conn: sqlite3.Connection) -> list[dict]:
    """Extract preference pairs from multi-attempt subtasks.

    When a subtask took multiple attempts, earlier attempts failed validation
    (rejected) while the final attempt succeeded (chosen). This is the most
    direct signal — same prompt, same model, accepted vs rejected output.
    """
    cursor = conn.execute("""
        SELECT id, prompt, generated_code, attempts_log, status, expert_model, domain
        FROM build_subtasks
        WHERE attempts_log IS NOT NULL
          AND prompt IS NOT NULL
          AND attempts > 1
    """)

    pairs = []
    for row in cursor:
        try:
            attempts_log = json.loads(row["attempts_log"])
        except (json.JSONDecodeError, TypeError):
            continue

        if not isinstance(attempts_log, list) or len(attempts_log) < 2:
            continue

        # Find the accepted attempt (chosen) and rejected attempts
        chosen = None
        rejected = []
        for attempt in attempts_log:
            if attempt.get("accepted"):
                chosen = attempt
            else:
                rejected.append(attempt)

        if chosen is None or not rejected:
            continue

        prompt = row["prompt"]
        system_prompt = _system_prompt_for_domain(row["domain"] or "backend")

        for rej in rejected:
            if not rej.get("output"):
                continue
            pairs.append({
                "prompt": prompt,
                "chosen": chosen["output"],
                "rejected": rej["output"],
                "source": "attempts",
                "model": row["expert_model"],
                "subtask_id": row["id"],
            })

    return pairs


def harvest_cross_subtask(conn: sqlite3.Connection) -> list[dict]:
    """Extract preference pairs from completed vs failed subtasks.

    When different subtasks in the same domain have one completed and one
    failed, we can create preference pairs. Less precise than attempt-based
    pairs but provides volume.
    """
    # Get all subtasks with prompts and generated code
    cursor = conn.execute("""
        SELECT id, prompt, generated_code, status, domain, expert_model, title
        FROM build_subtasks
        WHERE prompt IS NOT NULL
          AND generated_code IS NOT NULL
          AND status IN ('completed', 'failed')
        ORDER BY domain, created_at
    """)

    by_domain: dict[str, dict[str, list]] = {}
    for row in cursor:
        domain = row["domain"] or "general"
        if domain not in by_domain:
            by_domain[domain] = {"completed": [], "failed": []}
        by_domain[domain][row["status"]].append(dict(row))

    pairs = []
    for domain, groups in by_domain.items():
        completed = groups["completed"]
        failed = groups["failed"]

        if not completed or not failed:
            continue

        # Pair each failed subtask with a completed one from same domain
        for fail in failed:
            # Find the closest completed subtask (simple: just pick first)
            comp = completed[0]
            # Use the failed subtask's prompt as the shared prompt
            pairs.append({
                "prompt": fail["prompt"],
                "chosen": comp["generated_code"],
                "rejected": fail["generated_code"],
                "source": "cross_subtask",
                "domain": domain,
            })

    return pairs


def harvest_synthetic(
    sft_dataset: str | None,
    conn: sqlite3.Connection | None,
    model: str,
    ollama_host: str,
    ollama_port: int,
    num_candidates: int = 3,
) -> list[dict]:
    """Generate synthetic preference pairs by sampling the SFT model multiple times.

    For each prompt, generate N candidates, validate them (syntax check + length),
    and create pairs from best vs worst.
    """
    import urllib.request

    prompts = []

    # Source 1: existing subtask prompts from builder.db
    if conn is not None:
        cursor = conn.execute("""
            SELECT DISTINCT prompt, domain FROM build_subtasks
            WHERE prompt IS NOT NULL
            LIMIT 50
        """)
        for row in cursor:
            prompts.append({"prompt": row["prompt"], "domain": row["domain"] or "backend"})

    # Source 2: SFT dataset — reconstruct prompts from instruction field
    if sft_dataset and os.path.exists(sft_dataset):
        with open(sft_dataset) as f:
            for i, line in enumerate(f):
                if i >= 50:
                    break
                line = line.strip()
                if not line:
                    continue
                example = json.loads(line)
                messages = example.get("messages", [])
                user_msg = next((m["content"] for m in messages if m["role"] == "user"), None)
                if user_msg:
                    prompts.append({"prompt": user_msg, "domain": "backend"})

    if not prompts:
        print("No prompts available for synthetic generation")
        return []

    base_url = f"http://{ollama_host}:{ollama_port}/v1"
    pairs = []

    for pi, p in enumerate(prompts):
        print(f"\rSynthetic: {pi + 1}/{len(prompts)}", end="", flush=True)

        candidates = []
        for _ in range(num_candidates):
            try:
                payload = json.dumps({
                    "model": model,
                    "messages": [
                        {"role": "system", "content": _system_prompt_for_domain(p["domain"])},
                        {"role": "user", "content": p["prompt"]},
                    ],
                    "max_tokens": 4096,
                    "temperature": 0.8,  # Higher temp for diversity
                }).encode()
                req = urllib.request.Request(
                    f"{base_url}/chat/completions",
                    data=payload,
                    headers={"Content-Type": "application/json"},
                )
                with urllib.request.urlopen(req, timeout=120) as resp:
                    data = json.loads(resp.read())
                output = data["choices"][0]["message"]["content"]
                score = _score_output(output)
                candidates.append({"output": output, "score": score})
            except Exception as e:
                print(f"\n  Warning: generation failed: {e}")
                continue

        if len(candidates) < 2:
            continue

        # Sort by score, pair best vs worst
        candidates.sort(key=lambda c: c["score"], reverse=True)
        best = candidates[0]
        worst = candidates[-1]

        if best["score"] > worst["score"]:
            pairs.append({
                "prompt": p["prompt"],
                "chosen": best["output"],
                "rejected": worst["output"],
                "source": "synthetic",
                "chosen_score": best["score"],
                "rejected_score": worst["score"],
            })

    print()
    return pairs


def _score_output(output: str) -> float:
    """Score generated output heuristically (higher = better)."""
    score = 0.0

    # Length: prefer substantive output
    lines = output.strip().split("\n")
    if len(lines) > 5:
        score += 1.0
    if len(lines) > 20:
        score += 1.0

    # Has FILE markers: structured output
    if "--- FILE:" in output:
        score += 2.0

    # Has code blocks
    if "```" in output:
        score += 0.5

    # PHP syntax check if it looks like PHP
    if "<?php" in output:
        import subprocess
        import tempfile

        with tempfile.NamedTemporaryFile(mode="w", suffix=".php", delete=False) as f:
            # Extract first PHP block
            php_start = output.find("<?php")
            f.write(output[php_start:])
            f.flush()
            result = subprocess.run(
                ["php", "-l", f.name],
                capture_output=True, text=True,
            )
            os.unlink(f.name)
            if result.returncode == 0:
                score += 3.0  # Valid PHP is worth a lot
            else:
                score -= 2.0  # Syntax errors penalized

    # Penalize empty or boilerplate
    if len(output.strip()) < 50:
        score -= 3.0
    if "TODO" in output or "placeholder" in output.lower():
        score -= 1.0

    return score


def _system_prompt_for_domain(domain: str) -> str:
    if domain == "frontend":
        return "You are an expert frontend developer specializing in Bootstrap 5.3, jQuery, and PHP templates."
    return "You are an expert code generator. You write clean, production-ready code."


def format_dpo_pair(pair: dict, system_prompt: str = "You are a helpful coding assistant.") -> dict:
    """Format a preference pair for TRL DPOTrainer."""
    return {
        "prompt": [
            {"role": "system", "content": system_prompt},
            {"role": "user", "content": pair["prompt"]},
        ],
        "chosen": [
            {"role": "assistant", "content": pair["chosen"]},
        ],
        "rejected": [
            {"role": "assistant", "content": pair["rejected"]},
        ],
    }


def main():
    parser = argparse.ArgumentParser(description="Harvest DPO preference pairs")
    parser.add_argument("--db", default="data/builder.db", help="Path to builder.db")
    parser.add_argument("--sft-dataset", help="Path to SFT dataset.jsonl (for synthetic mode)")
    parser.add_argument("--output", default="data/dpo_pairs.jsonl", help="Output JSONL file")
    parser.add_argument("--system-prompt", default="You are a helpful coding assistant.",
                        help="System prompt for DPO training format")
    parser.add_argument("--synthetic", action="store_true",
                        help="Also generate synthetic preference pairs via model sampling")
    parser.add_argument("--model", default="php-expert",
                        help="Ollama model name for synthetic generation")
    parser.add_argument("--ollama-host", default="127.0.0.1", help="Ollama host")
    parser.add_argument("--ollama-port", type=int, default=11434, help="Ollama port")
    parser.add_argument("--num-candidates", type=int, default=3,
                        help="Number of candidates per prompt for synthetic generation")
    parser.add_argument("--stats", action="store_true", help="Show stats only, don't generate")
    args = parser.parse_args()

    all_pairs = []

    # Harvest from builder.db if it exists
    conn = None
    if os.path.exists(args.db):
        conn = connect_db(args.db)

        attempt_pairs = harvest_from_attempts(conn)
        cross_pairs = harvest_cross_subtask(conn)
        all_pairs.extend(attempt_pairs)
        all_pairs.extend(cross_pairs)

        print(f"From attempt retries: {len(attempt_pairs)} pairs")
        print(f"From cross-subtask:   {len(cross_pairs)} pairs")
    else:
        print(f"No builder.db found at {args.db} — skipping execution-based harvesting")

    # Synthetic generation
    if args.synthetic:
        print(f"\nGenerating synthetic pairs via {args.model}...")
        synth_pairs = harvest_synthetic(
            sft_dataset=args.sft_dataset,
            conn=conn,
            model=args.model,
            ollama_host=args.ollama_host,
            ollama_port=args.ollama_port,
            num_candidates=args.num_candidates,
        )
        all_pairs.extend(synth_pairs)
        print(f"Synthetic pairs:      {len(synth_pairs)} pairs")

    if conn:
        conn.close()

    if args.stats:
        print(f"\nTotal pairs available: {len(all_pairs)}")
        sources = {}
        for p in all_pairs:
            src = p.get("source", "unknown")
            sources[src] = sources.get(src, 0) + 1
        for src, count in sorted(sources.items()):
            print(f"  {src}: {count}")
        return

    if not all_pairs:
        print("\nNo preference pairs found.")
        print("Options:")
        print("  1. Run some builds first to generate execution data")
        print("  2. Use --synthetic with an existing SFT model to generate pairs")
        print("  3. Use --sft-dataset with --synthetic to sample from training prompts")
        sys.exit(0)

    # Write DPO-format JSONL
    output_path = Path(args.output)
    output_path.parent.mkdir(parents=True, exist_ok=True)

    with open(output_path, "w") as f:
        for pair in all_pairs:
            formatted = format_dpo_pair(pair, args.system_prompt)
            f.write(json.dumps(formatted) + "\n")

    print(f"\nWrote {len(all_pairs)} DPO preference pairs to {args.output}")


if __name__ == "__main__":
    main()
