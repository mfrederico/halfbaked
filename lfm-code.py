#!/usr/bin/env python3
"""
LFM Code — lightweight tool-calling CLI for local LLMs via Ollama.

Connects directly to Ollama, uses LFM2's native tool-call format,
and executes tools locally. No proxy needed.

Usage:
  python3 lfm-code.py
  python3 lfm-code.py --model myctobot-lfm2
  python3 lfm-code.py --ollama 127.0.0.1:11434
"""

import argparse
import atexit
import fnmatch
import glob
import json
import os
import re
import readline
import subprocess
import sys
from pathlib import Path
from urllib.request import Request, urlopen

# ── Tool-call parser ─────────────────────────────────────────────────

KNOWN_ARG_NAMES = {
    "file_path", "content", "old_string", "new_string", "command",
    "pattern", "path", "url", "prompt", "replace_all",
}

def parse_lfm2_tool_calls(text: str) -> list[dict] | None:
    """Parse LFM2-style tool calls from model output text.

    Handles unescaped quotes in values (common with PHP code) by only
    recognizing known argument names as key boundaries.
    """
    pattern = r'<\|tool_call_start\|>\s*\[(.*?)\]\s*<\|tool_call_end\|>'
    matches = re.findall(pattern, text, re.DOTALL)
    if not matches:
        return None

    tool_calls = []
    for match in matches:
        func_match = re.match(r'(\w+)\((.*)\)', match.strip(), re.DOTALL)
        if not func_match:
            continue

        func_name = func_match.group(1)
        args_str = func_match.group(2).strip()

        arguments = {}
        if args_str:
            key_pattern = '|'.join(re.escape(k) for k in KNOWN_ARG_NAMES)
            key_positions = [
                (m.group(1), m.start(), m.end())
                for m in re.finditer(rf'(?:^|,)\s*({key_pattern})\s*=\s*', args_str)
            ]

            for idx, (key, _kstart, val_start) in enumerate(key_positions):
                if val_start >= len(args_str):
                    break

                if args_str[val_start] == '"':
                    content_start = val_start + 1
                    if idx + 1 < len(key_positions):
                        next_kstart = key_positions[idx + 1][1]
                        end = next_kstart
                        while end > content_start and args_str[end - 1] in ' ,\n\r\t':
                            end -= 1
                        if end > content_start and args_str[end - 1] == '"':
                            end -= 1
                        arguments[key] = args_str[content_start:end]
                    else:
                        end = len(args_str)
                        while end > content_start and args_str[end - 1] in ' \n\r\t':
                            end -= 1
                        if end > content_start and args_str[end - 1] == '"':
                            end -= 1
                        arguments[key] = args_str[content_start:end]
                elif args_str[val_start] in '{[':
                    depth = 0
                    for i in range(val_start, len(args_str)):
                        if args_str[i] in '{[':
                            depth += 1
                        elif args_str[i] in '}]':
                            depth -= 1
                            if depth == 0:
                                try:
                                    arguments[key] = json.loads(args_str[val_start:i + 1])
                                except json.JSONDecodeError:
                                    arguments[key] = args_str[val_start:i + 1]
                                break
                else:
                    if idx + 1 < len(key_positions):
                        raw = args_str[val_start:key_positions[idx + 1][1]].rstrip(' ,\n\r\t')
                    else:
                        raw = args_str[val_start:].strip()
                    if raw.lower() == 'true':
                        arguments[key] = True
                    elif raw.lower() == 'false':
                        arguments[key] = False
                    else:
                        try:
                            arguments[key] = int(raw)
                        except ValueError:
                            arguments[key] = raw

        tool_calls.append({"name": func_name, "arguments": arguments})
    return tool_calls if tool_calls else None


# ── Tool definitions (for system prompt) ─────────────────────────────

TOOL_DEFS = [
    {
        "name": "Read",
        "description": "Read a file and return its contents",
        "parameters": {"file_path": "string (absolute path)"}
    },
    {
        "name": "Write",
        "description": "Write content to a file (creates or overwrites)",
        "parameters": {"file_path": "string (absolute path)", "content": "string"}
    },
    {
        "name": "Edit",
        "description": "Replace old_string with new_string in a file",
        "parameters": {"file_path": "string (absolute path)", "old_string": "string", "new_string": "string"}
    },
    {
        "name": "Bash",
        "description": "Run a shell command and return output",
        "parameters": {"command": "string"}
    },
    {
        "name": "Glob",
        "description": "Find files matching a glob pattern",
        "parameters": {"pattern": "string (e.g. **/*.php)", "path": "string (directory, optional)"}
    },
    {
        "name": "Grep",
        "description": "Search file contents for a regex pattern",
        "parameters": {"pattern": "string (regex)", "path": "string (file or directory)"}
    },
]

SYSTEM_PROMPT = """You are LFM Code, a coding assistant. You complete tasks by calling tools.

WORKFLOW:
1. First, Read any existing files mentioned by the user to understand context.
2. Then, Write the new file with complete working code.
3. If a file already exists and needs changes, use Edit.
4. Use Bash to run commands like php, composer, git.

RULES:
- To create a new file, use Write with file_path and content. Do NOT Read or Edit files that don't exist yet.
- Use absolute paths. Current directory: {cwd}
- One tool call per response. Do the most important action first.
- Keep text responses under 2 sentences.

EXAMPLE — creating a PHP file:
User: create hello.php
<|tool_call_start|>[Write(file_path="{cwd}/hello.php",content="<?php\\necho \\"Hello World\\";\\n?>")]<|tool_call_end|>

EXAMPLE — reading then writing:
User: read config.ini and create app.php
<|tool_call_start|>[Read(file_path="{cwd}/config.ini")]<|tool_call_end|>
(after receiving file contents, then write the new file)

List of tools: <|tool_list_start|>[{tools}]<|tool_list_end|>"""


# ── Harness intelligence ─────────────────────────────────────────────

def preflight_validate(name: str, args: dict) -> str | None:
    """Check tool call args before execution. Returns corrective message or None."""
    if name == "Write":
        if not args.get("file_path"):
            return ("Write requires file_path and content. "
                    "Call Write(file_path=\"/absolute/path/to/file.php\", content=\"<?php ...code... ?>\")")
        if not args.get("content"):
            return ("Write requires content. "
                    "Call Write(file_path=\"{}\", content=\"...your code here...\")".format(args["file_path"]))
    if name == "Edit":
        if not args.get("file_path") or not args.get("old_string"):
            return "Edit requires file_path, old_string, and new_string."
    if name == "Bash":
        if not args.get("command"):
            return "Bash requires a command. Call Bash(command=\"your command here\")"
    if name in ("Read", "Edit"):
        path = args.get("file_path", "")
        if path and not os.path.exists(os.path.expanduser(path)):
            return (f"File not found: {path}. "
                    "If you need to create this file, use Write instead of {}.".format(name))
    return None


def corrective_message(name: str, args: dict, error: str) -> str:
    """Generate a smarter error response that guides the model to recover."""
    if name == "Write" and "file_path" in error.lower():
        return ("CORRECTION: You called Write() without arguments. "
                "You MUST provide file_path and content. Example:\n"
                "<|tool_call_start|>[Write(file_path=\"/path/to/file.php\", "
                "content=\"<?php echo 'hello'; ?>\")]<|tool_call_end|>")

    if name in ("Read", "Edit") and "not found" in error:
        path = args.get("file_path", "unknown")
        return (f"CORRECTION: {path} does not exist. "
                f"To create a new file, use Write(file_path=\"{path}\", content=\"...code...\") instead.")

    if name == "Bash" and "command required" in error:
        return ("CORRECTION: You called Bash() without a command. "
                "Example: <|tool_call_start|>[Bash(command=\"ls -la\")]<|tool_call_end|>")

    return error


def detect_code_in_text(text: str, user_input: str) -> dict | None:
    """Detect when the model outputs code as plain text instead of using Write.

    If the user asked to create a file and the model dumped code, extract it
    and return a synthetic Write tool call.
    """
    # Check if user asked to create/write a file
    file_match = re.search(
        r'(?:create|write|make|generate|save)\s+(?:a\s+)?(?:\w+\s+)?(?:file\s+)?(?:called\s+|named\s+)?(\S+\.(?:php|py|js|ts|sh|json|html|css|sql|xml|yaml|yml))',
        user_input, re.IGNORECASE
    )
    if not file_match:
        return None

    filename = file_match.group(1)

    # Check if response contains a code block (```...``` or starts with <?php, #!, etc.)
    code = None

    # Markdown code block
    code_match = re.search(r'```(?:\w+)?\n(.*?)```', text, re.DOTALL)
    if code_match:
        code = code_match.group(1).strip()

    # Raw PHP/Python at start of response
    if not code:
        if text.strip().startswith('<?php') or text.strip().startswith('#!/'):
            # Grab everything up to a blank line followed by text explanation
            lines = text.strip().split('\n')
            code_lines = []
            for line in lines:
                # Stop at explanation text (line that doesn't look like code)
                if (code_lines and line.strip() and
                    not line.strip().startswith(('$', '//', '#', '*', '{', '}', '<?', '?>', 'use ',
                                                 'require', 'include', 'echo', 'return', 'if ', 'for',
                                                 'while', 'function', 'class', 'namespace', 'import',
                                                 'from ', 'def ', 'print', 'try', 'catch', 'throw')) and
                    not re.match(r'^\s', line) and
                    len(line) > 20 and
                    not any(c in line for c in (';', '(', ')', '{', '}', '=', '->'))):
                    break
                code_lines.append(line)
            if len(code_lines) >= 3:
                code = '\n'.join(code_lines).strip()

    if not code or len(code) < 20:
        return None

    # Build absolute path
    if os.path.isabs(filename):
        filepath = filename
    else:
        filepath = os.path.join(os.getcwd(), filename)

    return {"name": "Write", "arguments": {"file_path": filepath, "content": code}}


def prune_failed_exchanges(messages: list) -> list:
    """Remove consecutive failed tool exchanges from history to prevent degeneration.

    Keeps the system prompt, user messages, successful tool results,
    and the last failed exchange (so the model knows what just happened).
    """
    if len(messages) < 6:
        return messages

    pruned = [messages[0]]  # system prompt
    i = 1
    error_streak = 0

    while i < len(messages):
        msg = messages[i]
        role = msg.get("role", "")

        if role == "tool":
            content = msg.get("content", "")
            if "Error:" in content or "CORRECTION:" in content:
                error_streak += 1
                # Keep only the last 2 error exchanges
                if error_streak > 2 and i < len(messages) - 2:
                    # Skip this error and its preceding assistant message
                    if pruned and pruned[-1].get("role") == "assistant":
                        pruned.pop()
                    i += 1
                    continue
            else:
                error_streak = 0

        pruned.append(msg)
        i += 1

    return pruned


# ── Tool execution ───────────────────────────────────────────────────

def execute_tool(name: str, args: dict) -> str:
    """Execute a tool and return the result as a string."""
    try:
        if name == "Read":
            path = args.get("file_path", "")
            if not path:
                return "Error: file_path is required."
            path = os.path.expanduser(path)
            if not os.path.exists(path):
                return f"Error: file not found: {path}"
            with open(path) as f:
                content = f.read()
            if len(content) > 8000:
                content = content[:8000] + f"\n... (truncated, {len(content)} chars total)"
            return content

        elif name == "Write":
            path = args.get("file_path", "")
            content = args.get("content", "")
            if not path:
                return "Error: file_path is required."
            path = os.path.expanduser(path)
            os.makedirs(os.path.dirname(path) or ".", exist_ok=True)
            with open(path, "w") as f:
                f.write(content)
            return f"Written {len(content)} bytes to {path}"

        elif name == "Edit":
            path = args.get("file_path", "")
            old = args.get("old_string", "")
            new = args.get("new_string", "")
            if not path or not old:
                return "Error: file_path and old_string required"
            path = os.path.expanduser(path)
            if not os.path.exists(path):
                return f"Error: file not found: {path}"
            with open(path) as f:
                content = f.read()
            if old not in content:
                return f"Error: old_string not found in {path}"
            content = content.replace(old, new, 1)
            with open(path, "w") as f:
                f.write(content)
            return f"Edited {path}"

        elif name == "Bash":
            cmd = args.get("command", "")
            if not cmd:
                return "Error: command required"
            result = subprocess.run(
                cmd, shell=True, capture_output=True, text=True, timeout=30
            )
            output = ""
            if result.stdout:
                output += result.stdout
            if result.stderr:
                output += ("" if not output else "\n") + result.stderr
            output = output.strip()
            if not output:
                output = f"(no output, exit code {result.returncode})"
            elif result.returncode != 0:
                output += f"\n(exit code {result.returncode})"
            if len(output) > 4000:
                output = output[:4000] + "\n... (truncated)"
            return output

        elif name == "Glob":
            pattern = args.get("pattern", "")
            path = args.get("path", os.getcwd())
            if not pattern:
                return "Error: pattern required"
            full_pattern = os.path.join(path, pattern)
            matches = sorted(glob.glob(full_pattern, recursive=True))[:50]
            if not matches:
                return "No files found"
            return "\n".join(matches)

        elif name == "Grep":
            pattern = args.get("pattern", "")
            path = args.get("path", os.getcwd())
            if not pattern:
                return "Error: pattern required"
            try:
                result = subprocess.run(
                    ["grep", "-rn", "--include=*.php", "--include=*.py",
                     "--include=*.js", "--include=*.ts", "--include=*.json",
                     "-E", pattern, path],
                    capture_output=True, text=True, timeout=10
                )
                output = result.stdout.strip()
                if len(output) > 4000:
                    output = output[:4000] + "\n... (truncated)"
                return output or "No matches found"
            except subprocess.TimeoutExpired:
                return "Error: grep timed out"

        else:
            return f"Error: unknown tool '{name}'"

    except Exception as e:
        return f"Error: {e}"


# ── Ollama tools in native format ────────────────────────────────────

OLLAMA_TOOLS = [
    {
        "type": "function",
        "function": {
            "name": t["name"],
            "description": t["description"],
            "parameters": {
                "type": "object",
                "properties": {
                    k: {"type": "string", "description": v}
                    for k, v in t["parameters"].items()
                },
                "required": list(t["parameters"].keys()),
            },
        },
    }
    for t in TOOL_DEFS
]


# ── Ollama API ───────────────────────────────────────────────────────

def chat(ollama_host: str, model: str, messages: list,
         stream: bool = True, native_tools: bool = False) -> dict:
    """Send a chat request to Ollama.

    Returns: {"text": str, "tool_calls": list[dict] | None}
      - In LFM2 mode, tool_calls come from parsing text output.
      - In native mode, tool_calls come from Ollama's structured response.
    """
    url = f"http://{ollama_host}/api/chat"
    payload = {
        "model": model,
        "messages": messages,
        "stream": stream,
        "options": {"num_ctx": 32768},
    }

    if native_tools:
        payload["tools"] = OLLAMA_TOOLS

    req = Request(url, data=json.dumps(payload).encode(), method="POST")
    req.add_header("Content-Type", "application/json")

    full_text = ""
    native_tool_calls = None

    with urlopen(req, timeout=120) as resp:
        if stream:
            buf = b""
            for chunk in iter(lambda: resp.read(4096), b''):
                buf += chunk
                while b'\n' in buf:
                    line, buf = buf.split(b'\n', 1)
                    line = line.strip()
                    if not line:
                        continue
                    try:
                        data = json.loads(line)
                        msg = data.get("message", {})
                        token = msg.get("content", "")

                        # Check for native tool calls (Qwen/standard models)
                        if msg.get("tool_calls"):
                            native_tool_calls = native_tool_calls or []
                            for tc in msg["tool_calls"]:
                                func = tc.get("function", {})
                                native_tool_calls.append({
                                    "name": func.get("name", ""),
                                    "arguments": func.get("arguments", {}),
                                })

                        # Don't print tool call tokens to screen
                        if "<|tool_call_start|>" in full_text or "<|tool_call_start|>" in token:
                            full_text += token
                        else:
                            sys.stdout.write(token)
                            sys.stdout.flush()
                            full_text += token
                    except json.JSONDecodeError:
                        pass
        else:
            data = json.loads(resp.read())
            msg = data.get("message", {})
            full_text = msg.get("content", "")
            if msg.get("tool_calls"):
                native_tool_calls = [
                    {
                        "name": tc["function"]["name"],
                        "arguments": tc["function"]["arguments"],
                    }
                    for tc in msg["tool_calls"]
                ]

    return {"text": full_text, "tool_calls": native_tool_calls}


# ── Colors ───────────────────────────────────────────────────────────

BOLD = "\033[1m"
DIM = "\033[2m"
GREEN = "\033[32m"
YELLOW = "\033[33m"
CYAN = "\033[36m"
RED = "\033[31m"
RESET = "\033[0m"


# ── Main loop ────────────────────────────────────────────────────────

def main():
    parser = argparse.ArgumentParser(description="LFM Code — local LLM coding assistant")
    parser.add_argument("--model", default="myctobot-lfm2", help="Ollama model name")
    parser.add_argument("--ollama", default="127.0.0.1:11434", help="Ollama host:port")
    parser.add_argument("-p", "--prompt", help="Single prompt (non-interactive mode)")
    parser.add_argument("-v", "--verbose", action="store_true", help="Show raw tool call output")
    parser.add_argument("--native-tools", action="store_true",
                        help="Use Ollama native tool calling (for Qwen, Llama, etc.)")
    args = parser.parse_args()

    # Auto-detect: use native tools for non-LFM2 models
    if not args.native_tools and "lfm" not in args.model.lower():
        args.native_tools = True

    cwd = os.getcwd()
    tools_json = json.dumps(TOOL_DEFS)
    system = SYSTEM_PROMPT.format(cwd=cwd, tools=tools_json)

    # Readline: history + editing (arrow keys, ctrl-a/e, etc.)
    history_file = os.path.expanduser("~/.lfm_code_history")
    try:
        readline.read_history_file(history_file)
    except FileNotFoundError:
        pass
    readline.set_history_length(500)
    readline.parse_and_bind("set horizontal-scroll-mode off")
    atexit.register(readline.write_history_file, history_file)

    if args.native_tools:
        # Native tool models get a cleaner system prompt (tools sent via API)
        system = f"""You are LFM Code, a coding assistant. You complete tasks by calling tools.

WORKFLOW:
1. First, Read any existing files mentioned by the user to understand context.
2. Then, Write the new file with complete working code.
3. If a file already exists and needs changes, use Edit.
4. Use Bash to run commands like php, composer, git.

RULES:
- To create a new file, use Write with file_path and content.
- Do NOT Read or Edit files that don't exist yet.
- Use absolute paths. Current directory: {cwd}
- One tool call per response. Do the most important action first.
- Keep text responses under 2 sentences."""

    messages = [{"role": "system", "content": system}]

    mode_label = "native tools" if args.native_tools else "LFM2 format"
    print(f"{BOLD}LFM Code{RESET} — {args.model} ({mode_label})")
    print(f"{DIM}Tools: Read, Write, Edit, Bash, Glob, Grep{RESET}")
    print(f"{DIM}Working directory: {cwd}{RESET}")
    print()

    if args.prompt:
        prompts = [args.prompt]
    else:
        prompts = None

    prompt_idx = 0
    while True:
        try:
            if prompts:
                if prompt_idx >= len(prompts):
                    break
                user_input = prompts[prompt_idx]
                prompt_idx += 1
                print(f"{GREEN}>{RESET} {user_input}")
            else:
                # \001/\002 are readline escape markers — they tell readline
                # not to count ANSI codes in the line length calculation,
                # which fixes line wrapping.
                user_input = input(f"\001{GREEN}\002>\001{RESET}\002 ").strip()

            if not user_input:
                continue
            if user_input.lower() in ("exit", "quit", "/quit", "/exit"):
                break
            if user_input.startswith("!"):
                os.system(user_input[1:])
                continue
            if user_input == "/clear":
                messages = [{"role": "system", "content": system}]
                print(f"{DIM}Conversation cleared.{RESET}")
                continue

        except (KeyboardInterrupt, EOFError):
            print()
            break

        messages.append({"role": "user", "content": user_input})

        # Tool loop — keep going until model stops calling tools
        max_rounds = 5
        consecutive_errors = 0
        last_error = ""
        for _round in range(max_rounds):
            # Prune failed exchanges to keep context clean
            if _round > 1:
                messages = prune_failed_exchanges(messages)

            print(f"\n{CYAN}●{RESET} ", end="")
            try:
                result = chat(args.ollama, args.model, messages,
                              stream=True, native_tools=args.native_tools)
            except Exception as e:
                print(f"\n{RED}Error: {e}{RESET}")
                break

            response = result["text"]
            print()  # newline after response

            # Debug: show raw tool call if verbose
            if args.verbose and "<|tool_call_start|>" in response:
                raw_tc = re.search(r'<\|tool_call_start\|>(.*?)<\|tool_call_end\|>', response, re.DOTALL)
                if raw_tc:
                    print(f"  {DIM}[raw: {raw_tc.group(1)[:300]}]{RESET}")

            # Get tool calls — native from Ollama or parsed from LFM2 text
            tool_calls = result["tool_calls"]
            if not tool_calls:
                tool_calls = parse_lfm2_tool_calls(response)

            # Fallback: detect code dumped as plain text
            if not tool_calls:
                fallback = detect_code_in_text(response, user_input)
                if fallback:
                    print(f"  {DIM}[auto-detected code output, writing to file]{RESET}")
                    tool_calls = [fallback]

            if not tool_calls:
                messages.append({"role": "assistant", "content": response})
                break

            messages.append({"role": "assistant", "content": response})

            for tc in tool_calls:
                name = tc["name"]
                tool_args = tc["arguments"]

                # Pre-flight validation — catch bad calls before executing
                preflight_err = preflight_validate(name, tool_args)
                if preflight_err:
                    print(f"  {YELLOW}⚡ {name}{RESET}({DIM}...{RESET})")
                    print(f"  {RED}→ {preflight_err}{RESET}")
                    messages.append({
                        "role": "tool",
                        "content": f"<|tool_response_start|>CORRECTION: {preflight_err}<|tool_response_end|>",
                    })
                    if preflight_err == last_error:
                        consecutive_errors += 1
                    else:
                        consecutive_errors = 1
                        last_error = preflight_err
                    continue

                args_summary = ", ".join(f"{k}={repr(v)[:60]}" for k, v in tool_args.items())
                print(f"  {YELLOW}⚡ {name}{RESET}({DIM}{args_summary}{RESET})")

                result = execute_tool(name, tool_args)

                # Show truncated result
                preview = result[:200].replace('\n', '\\n')
                if len(result) > 200:
                    preview += "..."
                print(f"  {DIM}→ {preview}{RESET}")

                # Generate corrective message if error
                if result.startswith("Error:"):
                    result = corrective_message(name, tool_args, result)
                    if result == last_error:
                        consecutive_errors += 1
                    else:
                        consecutive_errors = 1
                        last_error = result
                else:
                    consecutive_errors = 0
                    last_error = ""

                messages.append({
                    "role": "tool",
                    "content": f"<|tool_response_start|>{result}<|tool_response_end|>",
                })

            if consecutive_errors >= 2:
                print(f"\n  {RED}Stopping: repeated errors. Try rephrasing your request.{RESET}")
                break
        else:
            print(f"\n{DIM}(max tool rounds reached){RESET}")

        print()


if __name__ == "__main__":
    main()
