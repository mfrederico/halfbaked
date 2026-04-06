#!/usr/bin/env python3
"""
Generate tool-use training data matching Claude Code's tool format.

Produces multi-turn conversations where the assistant uses tools like
Write, Read, Bash, Edit, Glob, Grep — the exact tools Claude Code
sends to models via Ollama.

Output format: JSONL with Qwen2.5 <tool_call>/<tool_response> format.

Usage:
  python3 generate_tool_data.py --output data/tool_training.jsonl --count 200
  python3 generate_tool_data.py --output data/tool_training.jsonl --count 200 --api-key sk-...
"""

import argparse
import json
import os
import random
import sys
import time

try:
    import anthropic
except ImportError:
    print("Installing anthropic SDK...")
    os.system(f"{sys.executable} -m pip install anthropic")
    import anthropic


# ─── Claude Code Tool Definitions (simplified for training) ──────────────────

TOOLS = [
    {
        "name": "Read",
        "description": "Read a file from the filesystem. Returns file contents with line numbers.",
        "parameters": {
            "type": "object",
            "properties": {
                "file_path": {"type": "string", "description": "Absolute path to the file to read"},
                "limit": {"type": "integer", "description": "Number of lines to read"},
                "offset": {"type": "integer", "description": "Line number to start reading from"}
            },
            "required": ["file_path"]
        }
    },
    {
        "name": "Write",
        "description": "Write content to a file. Creates the file if it doesn't exist, overwrites if it does.",
        "parameters": {
            "type": "object",
            "properties": {
                "file_path": {"type": "string", "description": "Absolute path to the file to write"},
                "content": {"type": "string", "description": "The content to write to the file"}
            },
            "required": ["file_path", "content"]
        }
    },
    {
        "name": "Edit",
        "description": "Replace a specific string in a file with new content.",
        "parameters": {
            "type": "object",
            "properties": {
                "file_path": {"type": "string", "description": "Absolute path to the file to modify"},
                "old_string": {"type": "string", "description": "The text to find and replace"},
                "new_string": {"type": "string", "description": "The replacement text"}
            },
            "required": ["file_path", "old_string", "new_string"]
        }
    },
    {
        "name": "Bash",
        "description": "Execute a shell command and return its output.",
        "parameters": {
            "type": "object",
            "properties": {
                "command": {"type": "string", "description": "The command to execute"},
                "timeout": {"type": "integer", "description": "Timeout in milliseconds"}
            },
            "required": ["command"]
        }
    },
    {
        "name": "Glob",
        "description": "Find files matching a glob pattern.",
        "parameters": {
            "type": "object",
            "properties": {
                "pattern": {"type": "string", "description": "Glob pattern like **/*.php or src/**/*.ts"},
                "path": {"type": "string", "description": "Directory to search in"}
            },
            "required": ["pattern"]
        }
    },
    {
        "name": "Grep",
        "description": "Search file contents using regex patterns.",
        "parameters": {
            "type": "object",
            "properties": {
                "pattern": {"type": "string", "description": "Regex pattern to search for"},
                "path": {"type": "string", "description": "File or directory to search"},
                "glob": {"type": "string", "description": "File glob filter like *.php"}
            },
            "required": ["pattern"]
        }
    }
]

# Format tools as the Qwen2.5 template expects them
TOOLS_SECTION = "\n\n# Tools\n\nYou may call one or more functions to assist with the user query.\n\n"
TOOLS_SECTION += "You are provided with function signatures within <tools></tools> XML tags:\n<tools>\n"
for tool in TOOLS:
    TOOLS_SECTION += json.dumps({"type": "function", "function": tool}) + "\n"
TOOLS_SECTION += "</tools>\n\n"
TOOLS_SECTION += "For each function call, return a json object with function name and arguments within <tool_call></tool_call> XML tags:\n"
TOOLS_SECTION += '<tool_call>\n{"name": <function-name>, "arguments": <args-json-object>}\n</tool_call>'


# ─── Prompt for Claude to generate conversations ─────────────────────────────

GENERATION_PROMPT = """Generate {n} realistic tool-use conversations for a PHP coding assistant.

The assistant has these tools available:
- Read: read files (file_path, optional limit/offset)
- Write: create/overwrite files (file_path, content)
- Edit: replace strings in files (file_path, old_string, new_string)
- Bash: run shell commands (command)
- Glob: find files by pattern (pattern, optional path)
- Grep: search file contents by regex (pattern, optional path/glob)

IMPORTANT RULES:
1. The assistant MUST use tool calls, not just describe code
2. File paths must be absolute (e.g., /home/user/project/file.php)
3. The assistant should use Write to create files, Bash to test them
4. Include realistic tool results (file contents, command output, search results)
5. The final response should be a brief summary, NOT the code again
6. Vary complexity: some single-tool, some multi-tool chains

Return a JSON array where each element has this EXACT structure:
{{
    "user_request": "Create a PHP script that...",
    "steps": [
        {{
            "tool_name": "Write",
            "arguments": {{"file_path": "/home/user/project/script.php", "content": "<?php\\n..."}},
            "result": "File written successfully"
        }},
        {{
            "tool_name": "Bash",
            "arguments": {{"command": "php /home/user/project/script.php"}},
            "result": "Hello World\\nScript executed successfully"
        }}
    ],
    "final_response": "Created script.php and verified it works. It outputs..."
}}

{context}

Generate diverse tasks: creating scripts, reading configs, editing files, searching codebases, running tests, fixing bugs, etc.
Focus on PHP with FlightPHP, RedBeanPHP, Swoole, and Ollama API integration."""


CONTEXT_VARIANTS = [
    "The project is a PHP chatbot platform using FlightPHP for routing and RedBeanPHP for database.",
    "The project is a warehouse management system (WMS) with PHP backend and SQLite database.",
    "The project uses Swoole for async PHP with coroutines and channels for concurrent processing.",
    "The project integrates with Ollama for local LLM inference, using PHP's curl functions.",
    "The project is a CLI tool built in PHP that manages deployment pipelines.",
    "The project uses RedBeanPHP for ORM with multiple SQLite databases and FlightPHP middleware.",
    "",  # No context — general PHP tasks
]


def generate_batch(client: anthropic.Anthropic, model: str, batch_size: int) -> list[dict]:
    """Generate a batch of tool-use conversations via Claude."""
    context = random.choice(CONTEXT_VARIANTS)
    if context:
        context = f"Project context: {context}"

    prompt = GENERATION_PROMPT.replace("{n}", str(batch_size)).replace("{context}", context)

    response = client.messages.create(
        model=model,
        max_tokens=8192,
        system="You are generating training data. Output ONLY valid JSON arrays. No markdown fences, no commentary.",
        messages=[{"role": "user", "content": prompt}],
    )

    text = response.content[0].text.strip()

    # Parse JSON response
    try:
        data = json.loads(text)
        if isinstance(data, list):
            return data
    except json.JSONDecodeError:
        pass

    # Try extracting JSON array from markdown
    if "```" in text:
        import re
        match = re.search(r'```(?:json)?\s*\n?(.*?)```', text, re.DOTALL)
        if match:
            try:
                return json.loads(match.group(1))
            except json.JSONDecodeError:
                pass

    # Try bracket extraction
    start = text.find('[')
    end = text.rfind(']')
    if start >= 0 and end > start:
        try:
            return json.loads(text[start:end + 1])
        except json.JSONDecodeError:
            pass

    return []


def format_conversation(conv: dict, system_prompt: str) -> dict | None:
    """Format a single conversation into Qwen2.5 training format."""
    user_request = conv.get("user_request", "")
    steps = conv.get("steps", [])
    final_response = conv.get("final_response", "")

    if not user_request or not steps or not final_response:
        return None

    full_system = system_prompt + TOOLS_SECTION

    messages = [
        {"role": "system", "content": full_system},
        {"role": "user", "content": user_request},
    ]

    for step in steps:
        tool_name = step.get("tool_name", "")
        arguments = step.get("arguments", {})
        result = step.get("result", "")

        if not tool_name:
            continue

        # Assistant makes a tool call
        tool_call_text = "<tool_call>\n" + json.dumps({
            "name": tool_name,
            "arguments": arguments
        }) + "\n</tool_call>"
        messages.append({"role": "assistant", "content": tool_call_text})

        # Tool responds
        result_text = "<tool_response>\n" + (
            json.dumps(result) if isinstance(result, (dict, list)) else str(result)
        ) + "\n</tool_response>"
        messages.append({"role": "user", "content": result_text})

    # Final assistant response
    messages.append({"role": "assistant", "content": final_response})

    return {"messages": messages}


def main():
    parser = argparse.ArgumentParser(description="Generate tool-use training data")
    parser.add_argument("--output", default="data/tool_training.jsonl", help="Output JSONL file")
    parser.add_argument("--count", type=int, default=200, help="Target number of examples")
    parser.add_argument("--batch-size", type=int, default=5, help="Examples per API call")
    parser.add_argument("--api-key", help="Anthropic API key")
    parser.add_argument("--model", default="claude-sonnet-4-20250514", help="Claude model to use")
    parser.add_argument("--system-prompt", default="You are a helpful coding assistant. You write clean, efficient, production-ready code.",
                        help="System prompt for training format")
    parser.add_argument("--dry-run", action="store_true", help="Show prompt only")
    args = parser.parse_args()

    api_key = args.api_key or os.environ.get("ANTHROPIC_API_KEY")
    if not api_key and not args.dry_run:
        # Try loading from config
        ini_path = os.path.join(os.path.dirname(__file__), "..", "conf", "anthropic.ini")
        if os.path.exists(ini_path):
            with open(ini_path) as f:
                for line in f:
                    if line.startswith("anthropic_key="):
                        api_key = line.split("=", 1)[1].strip()
        if not api_key:
            print("ERROR: Set ANTHROPIC_API_KEY or pass --api-key")
            sys.exit(1)

    if args.dry_run:
        print(GENERATION_PROMPT.replace("{n}", str(args.batch_size)).replace("{context}", "Project context: example"))
        return

    client = anthropic.Anthropic(api_key=api_key)

    os.makedirs(os.path.dirname(args.output) or ".", exist_ok=True)

    total = 0
    batches = (args.count + args.batch_size - 1) // args.batch_size

    print(f"Generating {args.count} tool-use examples ({batches} API calls)")
    print(f"Model: {args.model}")
    print(f"Output: {args.output}")

    with open(args.output, "w") as f:
        for i in range(batches):
            if total >= args.count:
                break

            print(f"\rBatch {i + 1}/{batches} | {total} examples", end="", flush=True)

            try:
                conversations = generate_batch(client, args.model, args.batch_size)

                for conv in conversations:
                    formatted = format_conversation(conv, args.system_prompt)
                    if formatted and len(formatted["messages"]) >= 5:  # At least: system, user, tool_call, tool_response, final
                        f.write(json.dumps(formatted) + "\n")
                        total += 1

                f.flush()
                time.sleep(0.5)

            except Exception as e:
                print(f"\nError on batch {i + 1}: {e}")
                time.sleep(2)
                continue

    print(f"\n\nDone! Generated {total} tool-use training examples")
    print(f"Output: {args.output}")

    # Stats
    if os.path.exists(args.output):
        multi_tool = 0
        single_tool = 0
        with open(args.output) as f:
            for line in f:
                d = json.loads(line)
                tool_calls = sum(1 for m in d["messages"] if m["role"] == "assistant" and "<tool_call>" in m.get("content", ""))
                if tool_calls > 1:
                    multi_tool += 1
                else:
                    single_tool += 1
        print(f"\nBreakdown:")
        print(f"  Single-tool conversations: {single_tool}")
        print(f"  Multi-tool conversations:  {multi_tool}")


if __name__ == "__main__":
    main()
