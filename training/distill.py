#!/usr/bin/env python3
"""
Multi-language dataset generator via Claude distillation.
Reads code_samples.jsonl, generates instruction-response pairs.

Usage:
  python3 distill.py --config config.json
  python3 distill.py --config config.json --resume
  python3 distill.py --config config.json --dry-run
"""

import argparse
import json
import os
import random
import re
import sys
import time
from pathlib import Path

try:
    import anthropic
except ImportError:
    print("Installing anthropic SDK...")
    os.system(f"{sys.executable} -m pip install anthropic")
    import anthropic


def load_config(config_path: str) -> dict:
    with open(config_path) as f:
        return json.loads(f.read())


def load_samples(samples_file: str) -> list[dict]:
    samples = []
    with open(samples_file) as f:
        for line in f:
            line = line.strip()
            if line:
                samples.append(json.loads(line))
    return samples


def load_progress(progress_file: str) -> dict:
    if os.path.exists(progress_file):
        return json.loads(Path(progress_file).read_text())
    return {"completed": 0, "pairs_generated": 0, "batch_index": 0}


def save_progress(progress_file: str, progress: dict):
    Path(progress_file).write_text(json.dumps(progress))


def get_code_for_sample(sample: dict) -> tuple[str, str]:
    if sample["type"] == "method":
        code = sample["method"]["code"]
        if sample["method"].get("docblock"):
            code = sample["method"]["docblock"] + "\n" + code
        return code, sample["metadata"]["file"]
    elif sample["type"] == "component":
        code = sample["component"]["code"]
        return code, sample["metadata"]["file"]
    else:
        content = sample["content"]
        lines = content.split("\n")
        if len(lines) > 150:
            content = "\n".join(lines[:150]) + "\n// ... (truncated)"
        return content, sample["metadata"]["file"]


# --- JSON Parsing (4-strategy, handles code in JSON strings) ---

def parse_response(text: str) -> list[dict]:
    text = text.strip()

    # Strategy 1: Direct JSON parse
    try:
        data = json.loads(text)
        if isinstance(data, list):
            return _filter_pairs(data)
    except json.JSONDecodeError:
        pass

    # Strategy 2: Strip markdown code fences
    cleaned = text
    if cleaned.startswith("```"):
        first_nl = cleaned.find("\n")
        if first_nl > 0:
            cleaned = cleaned[first_nl + 1:]
        if cleaned.rstrip().endswith("```"):
            cleaned = cleaned.rstrip()[:-3].rstrip()
        try:
            data = json.loads(cleaned)
            if isinstance(data, list):
                return _filter_pairs(data)
        except json.JSONDecodeError:
            pass

    # Strategy 3: Bracket-depth scanner respecting JSON strings
    pairs = _extract_json_array(text)
    if pairs is not None:
        return _filter_pairs(pairs)

    # Strategy 4: Regex extraction of individual objects
    results = []
    for m in re.finditer(r'\{\s*"instruction"\s*:', text):
        start = m.start()
        depth = 0
        in_string = False
        escape = False
        for i in range(start, len(text)):
            c = text[i]
            if escape:
                escape = False
                continue
            if c == '\\':
                escape = True
                continue
            if c == '"' and not escape:
                in_string = not in_string
                continue
            if in_string:
                continue
            if c == '{':
                depth += 1
            elif c == '}':
                depth -= 1
                if depth == 0:
                    try:
                        obj = json.loads(text[start:i + 1])
                        if "instruction" in obj and "response" in obj:
                            results.append(obj)
                    except json.JSONDecodeError:
                        pass
                    break
    return results


def _extract_json_array(text: str) -> list | None:
    start = -1
    for i, c in enumerate(text):
        if c == '[':
            start = i
            break
    if start < 0:
        return None

    depth = 0
    in_string = False
    escape = False
    for i in range(start, len(text)):
        c = text[i]
        if escape:
            escape = False
            continue
        if c == '\\':
            escape = True
            continue
        if c == '"' and not escape:
            in_string = not in_string
            continue
        if in_string:
            continue
        if c == '[':
            depth += 1
        elif c == ']':
            depth -= 1
            if depth == 0:
                try:
                    return json.loads(text[start:i + 1])
                except json.JSONDecodeError:
                    return None
    return None


def _filter_pairs(data: list) -> list[dict]:
    return [
        p for p in data
        if isinstance(p, dict) and "instruction" in p and "response" in p
    ]


def format_for_training(instruction: str, response: str, system_prompt: str) -> dict:
    return {
        "messages": [
            {"role": "system", "content": system_prompt},
            {"role": "user", "content": instruction},
            {"role": "assistant", "content": response},
        ]
    }


def format_tool_conversation(conversation: dict, system_prompt: str) -> dict | None:
    """Format a tool-calling conversation for training.

    Expected input from Claude:
    {
        "tools": [{"name": "...", "description": "...", "parameters": {...}}],
        "user_request": "...",
        "tool_call": {"name": "...", "arguments": {...}},
        "tool_result": "...",
        "final_response": "..."
    }
    """
    try:
        tools = conversation.get("tools", [])
        user_req = conversation.get("user_request", "")
        tool_call = conversation.get("tool_call", {})
        tool_result = conversation.get("tool_result", "")
        final_response = conversation.get("final_response", "")

        if not all([tools, user_req, tool_call, final_response]):
            return None

        # Build the system prompt with tool definitions (Qwen2.5 format)
        tools_section = "\n\n# Tools\n\nYou may call one or more functions to assist with the user query.\n\n"
        tools_section += "You are provided with function signatures within <tools></tools> XML tags:\n<tools>\n"
        for tool in tools:
            tools_section += json.dumps({"type": "function", "function": tool}) + "\n"
        tools_section += "</tools>\n\n"
        tools_section += "For each function call, return a json object with function name and arguments within <tool_call></tool_call> XML tags:\n"
        tools_section += '<tool_call>\n{"name": <function-name>, "arguments": <args-json-object>}\n</tool_call>'

        full_system = system_prompt + tools_section

        # Build the tool call response
        tool_call_text = "<tool_call>\n" + json.dumps({
            "name": tool_call.get("name", ""),
            "arguments": tool_call.get("arguments", {})
        }) + "\n</tool_call>"

        # Build the tool response
        tool_response_text = "<tool_response>\n" + (
            json.dumps(tool_result) if isinstance(tool_result, (dict, list)) else str(tool_result)
        ) + "\n</tool_response>"

        return {
            "messages": [
                {"role": "system", "content": full_system},
                {"role": "user", "content": user_req},
                {"role": "assistant", "content": tool_call_text},
                {"role": "user", "content": tool_response_text},
                {"role": "assistant", "content": final_response},
            ]
        }
    except Exception:
        return None


TOOL_CALL_PROMPT = """Given this PHP code from a real project, generate {n} realistic tool-calling conversation examples.

Each example should show a user asking for something that requires calling a tool/function, the model deciding which tool to call, receiving the result, and giving a final answer.

Create realistic tools that a PHP developer would use: database queries, file operations, API calls, code analysis, running tests, etc.

Code context from `{file}`:
```php
{code}
```

Return a JSON array where each element has:
- "tools": array of tool definitions with "name", "description", "parameters" (JSON Schema)
- "user_request": what the user asks
- "tool_call": {{"name": "tool_name", "arguments": {{...}}}}
- "tool_result": realistic result data
- "final_response": assistant's answer incorporating the tool result

Make the tools and requests relevant to this codebase. Include tools like:
- query_database: run SQL/RedBean queries
- read_file: read project files
- run_php: execute PHP code
- list_routes: list FlightPHP routes
- check_syntax: validate PHP syntax
- search_code: grep through codebase"""


TOOL_CALL_STANDALONE_PROMPT = """Generate {n} realistic tool-calling conversation examples for a PHP coding assistant.

The assistant has access to tools for: database queries (RedBeanPHP), file operations, running PHP code, listing FlightPHP routes, checking syntax, searching codebases, managing Ollama models, and calling APIs.

Return a JSON array where each element has:
- "tools": array of tool definitions with "name", "description", "parameters" (JSON Schema)
- "user_request": what the user asks
- "tool_call": {{"name": "tool_name", "arguments": {{...}}}}
- "tool_result": realistic result data
- "final_response": assistant's answer incorporating the tool result

Vary the complexity: some simple single-tool calls, some that require interpreting results.
Make requests realistic: checking logs, querying data, finding code patterns, running tests."""


def build_generation_plan(
    samples: list[dict],
    target: int,
    batch_size: int,
    prompt_templates: list[dict],
    standalone_prompts: list[dict],
    tool_call_ratio: float = 0.2,
) -> list[dict]:
    plan = []

    tool_budget = int(target * tool_call_ratio)
    code_budget = int((target - tool_budget) * 0.60)  # 60% code-context (up from 45%)
    standalone_budget = target - code_budget - tool_budget

    method_samples = [s for s in samples if s["type"] in ("method", "component")]
    file_samples = [s for s in samples if s["type"] == "full_file"]

    # Score methods by relevance
    scored_methods = []
    for s in method_samples:
        code = s.get("method", s.get("component", {})).get("code", "")
        score = len(code.split("\n"))
        if s.get("method", {}).get("docblock"):
            score += 10
        scored_methods.append((score, s))
    scored_methods.sort(key=lambda x: -x[0])

    # Score full files too — use them when methods are sparse
    scored_files = []
    for s in file_samples:
        content = s.get("content", "")
        lines = len(content.split("\n"))
        # Prefer medium-sized files (not trivially small, not huge)
        score = min(lines, 200) if lines >= 20 else 0
        if score > 0:
            scored_files.append((score, s))
    scored_files.sort(key=lambda x: -x[0])

    # Combine: methods first, then files as fallback
    code_samples_pool = [s for _, s in scored_methods]
    if len(code_samples_pool) < code_budget // batch_size:
        # Not enough methods — supplement with full files
        needed = (code_budget // batch_size) - len(code_samples_pool) + 10
        code_samples_pool.extend([s for _, s in scored_files[:needed]])

    # Code-context batches — cycle through all available samples with all templates
    num_code_batches = code_budget // batch_size
    if code_samples_pool:
        random.shuffle(code_samples_pool)
        templates = prompt_templates.copy()
        for i in range(num_code_batches):
            sample = code_samples_pool[i % len(code_samples_pool)]
            template = templates[i % len(templates)]
            code, filepath = get_code_for_sample(sample)
            plan.append({
                "type": "code_context",
                "category": template["category"],
                "prompt": template["prompt"].replace("{n}", str(batch_size)).replace("{file}", filepath).replace("{code}", code),
                "expected_pairs": batch_size,
            })

    # Standalone batches
    standalone_batch = 8
    for template in standalone_prompts:
        repeats = max(1, standalone_budget // (len(standalone_prompts) * standalone_batch))
        for _ in range(repeats):
            plan.append({
                "type": "standalone",
                "category": template["category"],
                "prompt": template["prompt"].replace("{n}", str(standalone_batch)),
                "expected_pairs": standalone_batch,
            })

    # Tool-calling batches (code-context + standalone)
    tool_batch = 3  # Fewer per batch — tool conversations are complex
    if tool_budget > 0:
        # Code-context tool calls
        all_samples = code_samples_pool or file_samples or samples
        tool_code_count = tool_budget // 2
        for i in range(tool_code_count // tool_batch):
            if not all_samples:
                break
            sample = all_samples[i % len(all_samples)]
            code, filepath = get_code_for_sample(sample)
            plan.append({
                "type": "tool_call",
                "category": "tool_call",
                "prompt": TOOL_CALL_PROMPT.replace("{n}", str(tool_batch)).replace("{file}", filepath).replace("{code}", code),
                "expected_pairs": tool_batch,
            })

        # Standalone tool calls
        tool_standalone_count = tool_budget - tool_code_count
        for _ in range(tool_standalone_count // tool_batch):
            plan.append({
                "type": "tool_call",
                "category": "tool_call_standalone",
                "prompt": TOOL_CALL_STANDALONE_PROMPT.replace("{n}", str(tool_batch)),
                "expected_pairs": tool_batch,
            })

    random.shuffle(plan)
    return plan


def main():
    parser = argparse.ArgumentParser(description="Multi-language dataset generator")
    parser.add_argument("--config", help="Config JSON file path")
    parser.add_argument("--samples", help="Code samples JSONL file")
    parser.add_argument("--output", help="Output dataset JSONL file")
    parser.add_argument("--api-key", help="API key (Anthropic or OpenAI-compatible)")
    parser.add_argument("--provider", choices=["anthropic", "openai"], default="anthropic",
                        help="LLM provider: 'anthropic' (Claude SDK) or 'openai' (OpenAI-compatible: Ollama, OpenRouter, Together, Groq, etc.)")
    parser.add_argument("--model", help="Model name (e.g. claude-sonnet-4-20250514, kimi2.5:cloud, gpt-4o)")
    parser.add_argument("--api-base", default="http://127.0.0.1:11434/v1", help="OpenAI-compatible API base URL (default: Ollama)")
    parser.add_argument("--target", type=int, default=1000, help="Target number of examples")
    parser.add_argument("--batch-size", type=int, default=5, help="Pairs per API call")
    parser.add_argument("--system-prompt", help="System prompt for generation")
    parser.add_argument("--resume", action="store_true", help="Resume from last run")
    parser.add_argument("--dry-run", action="store_true", help="Preview prompts only")
    parser.add_argument("--workers", type=int, default=1, help="Parallel API workers (default: 1, recommended: 5-10)")
    parser.add_argument("--pool", help="Training pool DB path — save examples to pool for accumulation across runs")
    parser.add_argument("--pool-source", default="", help="Source tag for pool entries (e.g., myctobot)")
    args = parser.parse_args()

    if args.config:
        config = load_config(args.config)
    else:
        config = {}

    samples_file = config.get("samples_file", args.samples or "code_samples.jsonl")
    output_file = config.get("output_file", args.output or "dataset.jsonl")
    api_key = config.get("api_key", args.api_key or os.environ.get("ANTHROPIC_API_KEY"))
    provider = config.get("provider", args.provider)
    model_name = args.model or config.get("model")
    api_base = config.get("api_base", config.get("ollama_host", args.api_base))
    # Normalize: if api_base doesn't end with /v1, append it (e.g. http://localhost:11434 → http://localhost:11434/v1)
    if api_base and not api_base.rstrip("/").endswith("/v1"):
        api_base = api_base.rstrip("/") + "/v1"
    system_prompt = config.get("system_prompt", args.system_prompt or "You are generating training data for a coding assistant.")
    prompt_templates = config.get("prompt_templates", [])
    standalone_prompts = config.get("standalone_prompts", [])
    target_examples = config.get("target_examples", args.target)
    batch_size = config.get("batch_size", args.batch_size)
    training_system_prompt = config.get("training_system_prompt", "You are a helpful coding assistant.")

    # Default model per provider
    if not model_name:
        model_name = "claude-sonnet-4-20250514" if provider == "anthropic" else "kimi2.5:cloud"

    # Map legacy "ollama" provider to "openai"
    if provider == "ollama":
        provider = "openai"

    if not os.path.exists(samples_file):
        print(f"Samples file not found: {samples_file}")
        print("Run extract.py first!")
        sys.exit(1)

    if not api_key and not args.dry_run:
        if provider == "anthropic":
            print("Set ANTHROPIC_API_KEY or pass --api-key")
            sys.exit(1)
        else:
            api_key = os.environ.get("OPENAI_API_KEY", "ollama")  # Ollama doesn't need a real key

    # Default templates if none provided
    if not prompt_templates:
        prompt_templates = [
            {"category": "generate", "prompt": "Study this code from a real project. Generate {n} instruction-response pairs where the instruction asks to write NEW functionality that follows the same patterns, and the response is COMPLETE working code (no placeholders).\n\nCode from `{file}`:\n```\n{code}\n```\n\nReturn a JSON array of objects with \"instruction\" and \"response\" fields."},
            {"category": "refactor", "prompt": "Given this code, generate {n} instruction-response pairs where the user asks to improve it. The response must be the COMPLETE improved version.\n\nCode from `{file}`:\n```\n{code}\n```\n\nReturn a JSON array of objects with \"instruction\" and \"response\" fields."},
            {"category": "extend", "prompt": "Given this code, generate {n} instruction-response pairs where the user asks to ADD a feature. The response must be COMPLETE working code.\n\nCode from `{file}`:\n```\n{code}\n```\n\nReturn a JSON array of objects with \"instruction\" and \"response\" fields."},
        ]
    if not standalone_prompts:
        standalone_prompts = [
            {"category": "patterns", "prompt": "Generate {n} instruction-response pairs about writing production code. Each response must be COMPLETE working code, not explanations.\n\nReturn a JSON array of objects with \"instruction\" and \"response\" fields."},
        ]

    samples = load_samples(samples_file)
    print(f"Loaded {len(samples)} code samples")

    plan = build_generation_plan(samples, target_examples, batch_size, prompt_templates, standalone_prompts)
    total_expected = sum(p["expected_pairs"] for p in plan)
    print(f"Generation plan: {len(plan)} API calls, ~{total_expected} expected pairs")

    if args.dry_run:
        for i, step in enumerate(plan[:5]):
            print(f"\n--- Batch {i + 1} ({step['category']}) ---")
            print(step["prompt"][:500] + "...")
        print(f"\n... and {len(plan) - 5} more batches")
        return

    # Progress tracking
    output_dir = str(Path(output_file).parent)
    progress_file = os.path.join(output_dir, ".generate_progress.json")
    progress = load_progress(progress_file) if args.resume else {"completed": 0, "pairs_generated": 0, "batch_index": 0}
    start_batch = progress["batch_index"]

    # Initialize LLM client based on provider
    if provider == "anthropic":
        client = anthropic.Anthropic(api_key=api_key)
    else:
        client = None

    def call_llm(sys_prompt: str, user_prompt: str) -> str:
        """Call the LLM provider and return response text. Retries on rate limit."""
        if provider == "anthropic":
            for attempt in range(5):
                try:
                    response = client.messages.create(
                        model=model_name,
                        max_tokens=4096,
                        system=sys_prompt,
                        messages=[{"role": "user", "content": user_prompt}],
                    )
                    return response.content[0].text
                except Exception as e:
                    if "429" in str(e) or "rate_limit" in str(e):
                        wait = 15 * (attempt + 1)
                        print(f"\n  Rate limited, waiting {wait}s...", end="", flush=True)
                        time.sleep(wait)
                        continue
                    raise
            raise RuntimeError("Rate limited after 5 retries")
        else:
            # OpenAI-compatible API (works with Ollama, OpenRouter, Together, Groq, OpenAI, etc.)
            import urllib.request
            url = f"{api_base}/chat/completions"
            headers = {"Content-Type": "application/json"}
            if api_key and api_key != "ollama":
                headers["Authorization"] = f"Bearer {api_key}"
            payload = json.dumps({
                "model": model_name,
                "messages": [
                    {"role": "system", "content": sys_prompt},
                    {"role": "user", "content": user_prompt},
                ],
                "max_tokens": 4096,
                "temperature": 0.7,
            }).encode()
            req = urllib.request.Request(url, data=payload, headers=headers)
            with urllib.request.urlopen(req, timeout=300) as resp:
                data = json.loads(resp.read())
            return data["choices"][0]["message"]["content"]

    workers = args.workers
    print(f"Provider: {provider} | Model: {model_name} | Workers: {workers}" + (f" | Base: {api_base}" if provider == "openai" else ""))

    # Initialize training pool if requested
    pool = None
    if args.pool:
        from pool import TrainingPool
        pool = TrainingPool(args.pool)
        existing = pool.count(source=args.pool_source) if args.pool_source else pool.count()
        print(f"Training pool: {existing} existing examples")

    mode = "a" if args.resume and os.path.exists(output_file) else "w"
    total_pairs = progress["pairs_generated"]

    import threading
    lock = threading.Lock()

    def process_batch(batch_info):
        """Process a single batch — called from thread pool."""
        i, step = batch_info
        try:
            text = call_llm(system_prompt, step["prompt"])
            pairs = parse_response(text)
            results = []

            for pair in pairs:
                if step.get("type") == "tool_call":
                    training_example = format_tool_conversation(
                        pair, training_system_prompt
                    )
                    if training_example is None:
                        continue
                    category = "tool_use"
                else:
                    training_example = format_for_training(
                        pair["instruction"], pair["response"], training_system_prompt
                    )
                    category = step.get("category", "code")
                results.append((training_example, category))

            return i, step, results, None
        except Exception as e:
            return i, step, [], e

    remaining_plan = plan[start_batch:]
    batch_infos = [(start_batch + idx, step) for idx, step in enumerate(remaining_plan)]

    if workers > 1:
        from concurrent.futures import ThreadPoolExecutor, as_completed

        with open(output_file, mode) as f:
            with ThreadPoolExecutor(max_workers=workers) as executor:
                futures = {}
                submitted = 0
                completed_count = 0

                # Submit initial batch of work
                for batch_info in batch_infos:
                    if total_pairs >= target_examples:
                        break
                    futures[executor.submit(process_batch, batch_info)] = batch_info
                    submitted += 1

                for future in as_completed(futures):
                    i, step, results, error = future.result()
                    completed_count += 1

                    if error:
                        print(f"\nError on batch {i + 1}: {error}")
                        continue

                    with lock:
                        for training_example, category in results:
                            if total_pairs >= target_examples:
                                break
                            f.write(json.dumps(training_example) + "\n")
                            total_pairs += 1

                            if pool:
                                pool.add(
                                    training_example["messages"],
                                    source=args.pool_source,
                                    category=category,
                                    distill_model=model_name,
                                )

                        f.flush()
                        progress = {
                            "completed": completed_count + start_batch,
                            "pairs_generated": total_pairs,
                            "batch_index": completed_count + start_batch,
                        }
                        save_progress(progress_file, progress)

                    print(
                        f"\rBatch {completed_count}/{len(remaining_plan)} | {step['category']:20s} | {total_pairs} pairs",
                        end="", flush=True,
                    )
    else:
        # Sequential mode (original behavior)
        with open(output_file, mode) as f:
            for i, step in enumerate(plan[start_batch:], start=start_batch):
                if total_pairs >= target_examples:
                    print(f"\nReached target of {target_examples} pairs!")
                    break

                print(
                    f"\rBatch {i + 1}/{len(plan)} | {step['category']:20s} | {total_pairs} pairs",
                    end="", flush=True,
                )

                try:
                    text = call_llm(system_prompt, step["prompt"])
                    pairs = parse_response(text)

                    for pair in pairs:
                        if step.get("type") == "tool_call":
                            training_example = format_tool_conversation(
                                pair, training_system_prompt
                            )
                            if training_example is None:
                                continue
                            category = "tool_use"
                        else:
                            training_example = format_for_training(
                                pair["instruction"], pair["response"], training_system_prompt
                            )
                            category = step.get("category", "code")

                        f.write(json.dumps(training_example) + "\n")
                        total_pairs += 1

                        if pool:
                            pool.add(
                                training_example["messages"],
                                source=args.pool_source,
                                category=category,
                                distill_model=model_name,
                            )

                    f.flush()
                    progress = {
                        "completed": i + 1,
                        "pairs_generated": total_pairs,
                        "batch_index": i + 1,
                    }
                    save_progress(progress_file, progress)
                    time.sleep(0.5)

                except Exception as e:
                    print(f"\nError on batch {i + 1}: {e}")
                    save_progress(progress_file, progress)
                    time.sleep(2)
                    continue

    print(f"\n\nDone! Generated {total_pairs} training pairs")
    print(f"Output: {output_file}")

    if os.path.exists(output_file):
        with open(output_file) as f:
            count = sum(1 for _ in f)
        print(f"Total examples in file: {count}")

    if pool:
        s = pool.stats()
        print(f"Training pool: {s['total']} total ({s['tool_use_examples']} tool-use, {s['code_examples']} code)")


if __name__ == "__main__":
    main()
