#!/usr/bin/env python3
"""
Ollama Tool-Call Translation Proxy

Sits between Claude Code and Ollama, translating LFM2's native tool-call
format into the JSON format Ollama expects. This allows LFM2 models to
work with Claude Code's tool-calling system.

Translates:
  <|tool_call_start|>[Write(file_path="/tmp/f.php", content="...")]<|tool_call_end|>
  →
  {"tool_calls": [{"function": {"name": "Write", "arguments": {"file_path": "/tmp/f.php", "content": "..."}}}]}

Usage:
  python3 ollama_proxy.py --port 11435 --upstream 127.0.0.1:11434
  OLLAMA_HOST=127.0.0.1:11435 claude --model ollama:myctobot-lfm2

Also usable as a library:
  from ollama_proxy import parse_lfm2_tool_calls
  tool_calls = parse_lfm2_tool_calls(model_output_text)
"""

import argparse
import json
import re
import sys
from http.server import HTTPServer, BaseHTTPRequestHandler
from urllib.request import Request, urlopen
from urllib.error import URLError


def parse_lfm2_tool_calls(text: str) -> list[dict] | None:
    """Parse LFM2-style tool calls from model output text.

    Input format:
      <|tool_call_start|>[FuncName(arg1="val1", arg2="val2")]<|tool_call_end|>

    Returns list of:
      {"function": {"name": "FuncName", "arguments": {"arg1": "val1", "arg2": "val2"}}}

    Returns None if no tool calls found.
    """
    # Find all tool call blocks
    pattern = r'<\|tool_call_start\|>\s*\[(.*?)\]\s*<\|tool_call_end\|>'
    matches = re.findall(pattern, text, re.DOTALL)

    if not matches:
        return None

    tool_calls = []
    for match in matches:
        # Parse: FuncName(arg1="val1", arg2="val2")
        func_match = re.match(r'(\w+)\((.*)\)', match.strip(), re.DOTALL)
        if not func_match:
            continue

        func_name = func_match.group(1)
        args_str = func_match.group(2).strip()

        # Parse keyword arguments
        # LFM2 does NOT escape inner quotes in string values, so we can't
        # rely on simple quote matching. Instead, we find all key= positions
        # first, then extract values by slicing between them.
        arguments = {}
        if args_str:
            # Find all key= positions
            key_positions = [(m.group(1), m.start(), m.end())
                             for m in re.finditer(r'(\w+)\s*=\s*', args_str)]

            for idx, (key, _kstart, val_start) in enumerate(key_positions):
                if val_start >= len(args_str):
                    break

                if args_str[val_start] == '"':
                    # String value — figure out where it ends
                    content_start = val_start + 1
                    if idx + 1 < len(key_positions):
                        # Not the last arg: value ends at ",<next_key>="
                        # Find the comma+whitespace before the next key
                        next_kstart = key_positions[idx + 1][1]
                        # Walk backwards from next key start to skip comma/whitespace
                        end = next_kstart
                        while end > content_start and args_str[end - 1] in ' ,\n\r\t':
                            end -= 1
                        # Strip the closing quote
                        if end > content_start and args_str[end - 1] == '"':
                            end -= 1
                        arguments[key] = args_str[content_start:end]
                    else:
                        # Last arg: value ends at the final " before end of args_str
                        # args_str ends at the closing ) of the function call
                        end = len(args_str)
                        # Strip trailing whitespace
                        while end > content_start and args_str[end - 1] in ' \n\r\t':
                            end -= 1
                        # Strip closing quote
                        if end > content_start and args_str[end - 1] == '"':
                            end -= 1
                        arguments[key] = args_str[content_start:end]
                elif args_str[val_start] in '{[':
                    # JSON value — find matching bracket
                    depth = 0
                    start = val_start
                    for i in range(val_start, len(args_str)):
                        if args_str[i] in '{[':
                            depth += 1
                        elif args_str[i] in '}]':
                            depth -= 1
                            if depth == 0:
                                try:
                                    arguments[key] = json.loads(args_str[start:i + 1])
                                except json.JSONDecodeError:
                                    arguments[key] = args_str[start:i + 1]
                                break
                else:
                    # Unquoted value (number, bool, etc.)
                    if idx + 1 < len(key_positions):
                        raw = args_str[val_start:key_positions[idx + 1][1]].rstrip(' ,\n\r\t')
                    else:
                        raw = args_str[val_start:].strip()
                    if raw.lower() == 'true':
                        arguments[key] = True
                    elif raw.lower() == 'false':
                        arguments[key] = False
                    elif raw.lower() == 'null':
                        arguments[key] = None
                    else:
                        try:
                            arguments[key] = int(raw)
                        except ValueError:
                            try:
                                arguments[key] = float(raw)
                            except ValueError:
                                arguments[key] = raw

        tool_calls.append({
            "function": {
                "name": func_name,
                "arguments": arguments,
            }
        })

    return tool_calls if tool_calls else None


def translate_response(response_body: bytes, is_anthropic: bool = False) -> bytes:
    """Translate an Ollama API response, converting LFM2 tool calls to JSON format."""
    try:
        data = json.loads(response_body)
    except json.JSONDecodeError:
        return response_body

    if is_anthropic:
        # Handle Anthropic /v1/messages responses
        content_blocks = data.get("content", [])
        full_text = ""
        for block in content_blocks:
            if block.get("type") == "text":
                full_text += block.get("text", "")

        if "<|tool_call_start|>" in full_text:
            tool_calls = parse_lfm2_tool_calls(full_text)
            if tool_calls:
                # Remove tool call text from content
                clean_text = re.sub(
                    r'<\|tool_call_start\|>.*?<\|tool_call_end\|>',
                    '', full_text, flags=re.DOTALL
                ).strip()

                new_content = []
                if clean_text:
                    new_content.append({"type": "text", "text": clean_text})
                for i, tc in enumerate(tool_calls):
                    new_content.append({
                        "type": "tool_use",
                        "id": f"toolu_{data.get('id', 'unknown')}_{i}",
                        "name": tc["function"]["name"],
                        "input": tc["function"]["arguments"],
                    })
                data["content"] = new_content
                data["stop_reason"] = "tool_use"
    else:
        # Handle /api/chat responses
        if "message" in data:
            msg = data["message"]
            content = msg.get("content", "")

            if "<|tool_call_start|>" in content:
                tool_calls = parse_lfm2_tool_calls(content)
                if tool_calls:
                    msg["tool_calls"] = tool_calls
                    # Remove the tool call text from content
                    msg["content"] = re.sub(
                        r'<\|tool_call_start\|>.*?<\|tool_call_end\|>',
                        '', content, flags=re.DOTALL
                    ).strip()
                    data["message"] = msg

    return json.dumps(data).encode()


def translate_streaming_line(line: bytes) -> bytes:
    """Translate a single streaming response line."""
    if not line.strip():
        return line
    try:
        data = json.loads(line)
        if "message" in data:
            content = data["message"].get("content", "")
            if "<|tool_call_start|>" in content:
                return translate_response(line)
        return line
    except json.JSONDecodeError:
        return line


def json_to_sse_events(data: dict) -> str:
    """Convert a complete Anthropic JSON response into SSE event stream."""
    events = []

    # message_start
    msg_start = {
        "type": "message_start",
        "message": {
            "id": data.get("id", "msg_proxy"),
            "type": "message",
            "role": "assistant",
            "model": data.get("model", "myctobot-lfm2"),
            "content": [],
            "stop_reason": None,
            "usage": {"input_tokens": data.get("usage", {}).get("input_tokens", 0),
                      "output_tokens": 0},
        }
    }
    events.append(f"event: message_start\ndata: {json.dumps(msg_start)}\n\n")

    # Content blocks
    for i, block in enumerate(data.get("content", [])):
        if block["type"] == "text":
            events.append(f"event: content_block_start\ndata: {json.dumps({'type': 'content_block_start', 'index': i, 'content_block': {'type': 'text', 'text': ''}})}\n\n")
            events.append(f"event: content_block_delta\ndata: {json.dumps({'type': 'content_block_delta', 'index': i, 'delta': {'type': 'text_delta', 'text': block['text']}})}\n\n")
            events.append(f"event: content_block_stop\ndata: {json.dumps({'type': 'content_block_stop', 'index': i})}\n\n")
        elif block["type"] == "tool_use":
            input_json = json.dumps(block["input"])
            events.append(f"event: content_block_start\ndata: {json.dumps({'type': 'content_block_start', 'index': i, 'content_block': {'type': 'tool_use', 'id': block['id'], 'name': block['name'], 'input': {}}})}\n\n")
            events.append(f"event: content_block_delta\ndata: {json.dumps({'type': 'content_block_delta', 'index': i, 'delta': {'type': 'input_json_delta', 'partial_json': input_json}})}\n\n")
            events.append(f"event: content_block_stop\ndata: {json.dumps({'type': 'content_block_stop', 'index': i})}\n\n")

    # message_delta + message_stop
    events.append(f"event: message_delta\ndata: {json.dumps({'type': 'message_delta', 'delta': {'stop_reason': data.get('stop_reason', 'end_turn')}, 'usage': {'output_tokens': data.get('usage', {}).get('output_tokens', 0)}})}\n\n")
    events.append(f"event: message_stop\ndata: {json.dumps({'type': 'message_stop'})}\n\n")

    return "".join(events)


class ProxyHandler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"
    upstream = "127.0.0.1:11434"
    verbose = False

    def do_GET(self):
        self._proxy("GET")

    def do_POST(self):
        self._proxy("POST")

    def do_DELETE(self):
        self._proxy("DELETE")

    def do_HEAD(self):
        self._proxy("HEAD")

    def _proxy(self, method):
        url = f"http://{self.upstream}{self.path}"

        # Read request body
        content_length = int(self.headers.get('Content-Length', 0))
        body = self.rfile.read(content_length) if content_length > 0 else None

        # Check if this is a streaming request and intercept tools
        is_streaming = False
        was_streaming = False  # Track if client originally asked for streaming
        has_tools = False
        saved_tools = None
        is_anthropic = self.path.startswith("/v1/messages")
        if body:
            try:
                req_data = json.loads(body)
                is_streaming = req_data.get("stream", True)  # Ollama defaults to streaming

                # Remap unknown models to our default LFM2 model
                model = req_data.get("model", "")
                if model and not model.startswith(("myctobot", "qwen", "nomic", "llama")):
                    req_data["model"] = "myctobot-lfm2"
                    body = json.dumps(req_data).encode()

                # KEY TRICK: Strip tools from request so Ollama doesn't try to parse
                # tool calls itself. Instead, inject tool definitions into the system
                # prompt so the model sees them, and we parse the output ourselves.
                if "tools" in req_data and (self.path == "/api/chat" or is_anthropic):
                    has_tools = True
                    saved_tools = req_data.pop("tools")
                    # Force non-streaming when we need to buffer for tool translation
                    was_streaming = is_streaming
                    req_data["stream"] = False
                    is_streaming = False

                    # Inject tool definitions into system message (LFM2 format)
                    tool_list = json.dumps(saved_tools)
                    tool_system = (
                        "\nYou MUST use tools to complete tasks. When asked to write or create files, "
                        "use the Write tool. When asked to read files, use the Read tool. "
                        "Always respond with tool calls, never paste code as plain text."
                        f"\nList of tools: <|tool_list_start|>[{tool_list}]<|tool_list_end|>"
                    )

                    if is_anthropic:
                        # Anthropic format: system is a separate field
                        system = req_data.get("system", "You are a helpful assistant.")
                        if isinstance(system, list):
                            # system can be a list of content blocks
                            system = " ".join(b.get("text", "") for b in system if b.get("type") == "text")
                        req_data["system"] = system + tool_system
                    else:
                        messages = req_data.get("messages", [])
                        if messages and messages[0]["role"] == "system":
                            messages[0]["content"] += tool_system
                        else:
                            messages.insert(0, {"role": "system", "content": "You are a helpful assistant." + tool_system})
                        req_data["messages"] = messages

                    body = json.dumps(req_data).encode()
            except json.JSONDecodeError:
                pass

        if self.verbose and has_tools:
            try:
                debug_data = json.loads(body)
                print(f"  [PROXY] Tools injected, stream={debug_data.get('stream')}, "
                      f"model={debug_data.get('model')}, "
                      f"system_len={len(str(debug_data.get('system', '')))}")
            except Exception:
                pass

        # Forward to upstream
        req = Request(url, data=body, method=method)
        for key, value in self.headers.items():
            if key.lower() not in ('host', 'content-length'):
                req.add_header(key, value)
        if body:
            req.add_header('Content-Length', str(len(body)))

        try:
            with urlopen(req, timeout=300) as resp:
                status = resp.status
                headers = resp.getheaders()

                if is_streaming and has_tools and is_anthropic:
                    # Anthropic SSE streaming with tools: buffer everything,
                    # then emit a single non-streaming JSON response.
                    accumulated_text = ""
                    msg_data = {}
                    buf = b""
                    for chunk in iter(lambda: resp.read(4096), b''):
                        buf += chunk
                    # Parse SSE events from the full buffer
                    for line in buf.decode(errors="replace").split("\n"):
                        line = line.strip()
                        if line.startswith("data: "):
                            try:
                                event = json.loads(line[6:])
                                etype = event.get("type", "")
                                if etype == "message_start":
                                    msg_data = event.get("message", {})
                                elif etype == "content_block_delta":
                                    delta = event.get("delta", {})
                                    if delta.get("type") == "text_delta":
                                        accumulated_text += delta.get("text", "")
                                elif etype == "message_delta":
                                    msg_data.update(event.get("delta", {}))
                                    msg_data.setdefault("usage", {}).update(
                                        event.get("usage", {}))
                            except json.JSONDecodeError:
                                pass

                    # Build a complete Anthropic response
                    msg_data["content"] = [{"type": "text", "text": accumulated_text}]
                    translated = translate_response(
                        json.dumps(msg_data).encode(), is_anthropic=True)

                    self.send_response(status)
                    self.send_header('Content-Type', 'application/json')
                    self.send_header('Content-Length', str(len(translated)))
                    self.end_headers()
                    self.wfile.write(translated)

                elif is_streaming and has_tools:
                    # Ollama streaming with tools: buffer NDJSON, emit single response
                    accumulated_content = ""
                    last_data = None
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
                                content = data.get("message", {}).get("content", "")
                                accumulated_content += content
                                if data.get("done"):
                                    last_data = data
                            except json.JSONDecodeError:
                                pass

                    if last_data:
                        last_data["message"] = last_data.get("message", {})
                        last_data["message"]["content"] = accumulated_content
                        last_data["message"]["role"] = "assistant"

                    response_body = json.dumps(last_data or {}).encode()
                    translated = translate_response(response_body)

                    self.send_response(status)
                    self.send_header('Content-Type', 'application/json')
                    self.send_header('Content-Length', str(len(translated)))
                    self.end_headers()
                    self.wfile.write(translated)

                elif is_streaming:
                    # No tools — pass through streaming as-is
                    self.send_response(status)
                    for key, value in headers:
                        if key.lower() not in ('transfer-encoding', 'content-length'):
                            self.send_header(key, value)
                    self.send_header('Transfer-Encoding', 'chunked')
                    self.end_headers()

                    for chunk in iter(lambda: resp.read(4096), b''):
                        hex_len = format(len(chunk), 'x')
                        self.wfile.write(f"{hex_len}\r\n".encode())
                        self.wfile.write(chunk)
                        self.wfile.write(b"\r\n")
                        self.wfile.flush()
                    self.wfile.write(b"0\r\n\r\n")
                    self.wfile.flush()
                else:
                    # Non-streaming — read full response and translate
                    response_body = resp.read()
                    translated = translate_response(
                        response_body, is_anthropic=is_anthropic)

                    if was_streaming and is_anthropic:
                        # Client wanted streaming — re-emit as SSE events
                        translated_data = json.loads(translated)
                        sse_body = json_to_sse_events(translated_data).encode()
                        self.send_response(status)
                        self.send_header('Content-Type', 'text/event-stream')
                        self.send_header('Content-Length', str(len(sse_body)))
                        self.end_headers()
                        self.wfile.write(sse_body)
                    else:
                        self.send_response(status)
                        for key, value in headers:
                            if key.lower() == 'content-length':
                                self.send_header(key, str(len(translated)))
                            elif key.lower() != 'transfer-encoding':
                                self.send_header(key, value)
                        self.end_headers()
                        self.wfile.write(translated)

        except Exception as e:
            # Pass through HTTP errors from upstream with their original status
            from urllib.error import HTTPError
            if isinstance(e, HTTPError):
                error_body = e.read()
                self.send_response(e.code)
                self.send_header('Content-Type', 'application/json')
                self.send_header('Content-Length', str(len(error_body)))
                self.end_headers()
                self.wfile.write(error_body)
                if self.verbose:
                    print(f"  {method} {self.path} → {e.code} (upstream)")
            else:
                error_msg = json.dumps({"error": f"Upstream error: {e}"}).encode()
                self.send_response(502)
                self.send_header('Content-Type', 'application/json')
                self.send_header('Content-Length', str(len(error_msg)))
                self.end_headers()
                self.wfile.write(error_msg)
                if self.verbose:
                    print(f"  {method} {self.path} → 502 ({e})")
            return

        if self.verbose:
            print(f"  {method} {self.path} → {status}")

    def log_message(self, format, *args):
        if self.verbose:
            super().log_message(format, *args)


def main():
    parser = argparse.ArgumentParser(description="Ollama Tool-Call Translation Proxy")
    parser.add_argument("--port", type=int, default=11435, help="Proxy listen port (default: 11435)")
    parser.add_argument("--upstream", default="127.0.0.1:11434", help="Upstream Ollama address")
    parser.add_argument("--verbose", action="store_true", help="Log all requests")
    args = parser.parse_args()

    ProxyHandler.upstream = args.upstream
    ProxyHandler.verbose = args.verbose

    server = HTTPServer(("127.0.0.1", args.port), ProxyHandler)
    print(f"LFM2 Tool-Call Proxy")
    print(f"  Listening: 127.0.0.1:{args.port}")
    print(f"  Upstream:  {args.upstream}")
    print(f"")
    print(f"Usage with Claude Code:")
    print(f"  OLLAMA_HOST=127.0.0.1:{args.port} claude --model ollama:myctobot-lfm2")
    print(f"")

    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print("\nProxy stopped.")


if __name__ == "__main__":
    main()
