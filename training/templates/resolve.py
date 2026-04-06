#!/usr/bin/env python3
"""
Resolve the correct Ollama Go template for a model.

Strategy (in priority order):
1. Check training/templates/ for a pre-tested .gotmpl file matching model_type
2. Query Ollama for a base model with the same architecture (steal its template)
3. Fall back to a generic chat template

Usage:
  python3 resolve.py --model-path /path/to/merged_model
  python3 resolve.py --model-type qwen2
  python3 resolve.py --model-type lfm2

Outputs the Go template to stdout.
"""

import argparse
import json
import os
import subprocess
import sys
from pathlib import Path

TEMPLATES_DIR = Path(__file__).parent

# Map model_type from config.json → Ollama base model names to try
OLLAMA_BASE_MODELS = {
    "qwen2": ["qwen2.5:7b", "qwen2.5:3b", "qwen2.5:0.5b"],
    "llama": ["llama3.2:3b", "llama3.1:8b", "llama3:8b"],
    "mistral": ["mistral:7b", "mistral:latest"],
    "gemma": ["gemma2:9b", "gemma:7b"],
    "phi": ["phi3:mini", "phi:latest"],
}

# Known stop tokens per model family
STOP_TOKENS = {
    "qwen2": ["<|im_end|>", "<|im_start|>"],
    "lfm2": ["<|im_end|>"],
    "llama": ["<|eot_id|>"],
    "mistral": ["</s>"],
    "gemma": ["<end_of_turn>"],
    "phi": ["<|end|>"],
}


def detect_model_type(model_path: str) -> str | None:
    """Detect model type from config.json."""
    config_path = os.path.join(model_path, "config.json")
    if not os.path.exists(config_path):
        return None
    with open(config_path) as f:
        config = json.load(f)
    return config.get("model_type", "").lower()


def get_local_template(model_type: str) -> str | None:
    """Check for a pre-tested .gotmpl file."""
    template_file = TEMPLATES_DIR / f"{model_type}.gotmpl"
    if template_file.exists():
        return template_file.read_text()
    return None


def get_ollama_template(model_type: str) -> str | None:
    """Try to steal the template from a matching Ollama base model."""
    candidates = OLLAMA_BASE_MODELS.get(model_type, [])
    for model_name in candidates:
        try:
            result = subprocess.run(
                ["ollama", "show", model_name, "--template"],
                capture_output=True, text=True, timeout=10,
            )
            if result.returncode == 0 and result.stdout.strip():
                return result.stdout.strip()
        except (subprocess.TimeoutExpired, FileNotFoundError):
            continue
    return None


def get_tokenizer_template(model_path: str) -> str | None:
    """Get the Jinja chat template from the tokenizer (for reference, not direct use)."""
    config_path = os.path.join(model_path, "tokenizer_config.json")
    if not os.path.exists(config_path):
        return None
    with open(config_path) as f:
        config = json.load(f)
    return config.get("chat_template")


def get_stop_tokens(model_type: str) -> list[str]:
    """Get stop tokens for a model family."""
    return STOP_TOKENS.get(model_type, ["<|im_end|>"])


def generic_template() -> str:
    """Fallback generic chat template (no tool support)."""
    return """{{- if .System }}<|im_start|>system
{{ .System }}<|im_end|>
{{ end }}{{ if .Prompt }}<|im_start|>user
{{ .Prompt }}<|im_end|>
{{ end }}<|im_start|>assistant
{{ .Response }}{{ if .Response }}<|im_end|>{{ end }}"""


def resolve(model_path: str = None, model_type: str = None) -> dict:
    """Resolve template, stop tokens, and metadata for a model.

    Returns:
        {
            "template": "...",       # Go template string
            "stop_tokens": [...],    # Stop token list
            "model_type": "...",     # Detected model type
            "source": "...",         # Where template came from
        }
    """
    if model_path and not model_type:
        model_type = detect_model_type(model_path)

    if not model_type:
        return {
            "template": generic_template(),
            "stop_tokens": ["<|im_end|>"],
            "model_type": "unknown",
            "source": "generic_fallback",
        }

    # Strategy 1: Pre-tested local template
    template = get_local_template(model_type)
    if template:
        return {
            "template": template,
            "stop_tokens": get_stop_tokens(model_type),
            "model_type": model_type,
            "source": f"local:{model_type}.gotmpl",
        }

    # Strategy 2: Steal from Ollama base model
    template = get_ollama_template(model_type)
    if template:
        return {
            "template": template,
            "stop_tokens": get_stop_tokens(model_type),
            "model_type": model_type,
            "source": f"ollama_base",
        }

    # Strategy 3: Generic fallback
    return {
        "template": generic_template(),
        "stop_tokens": get_stop_tokens(model_type),
        "model_type": model_type,
        "source": "generic_fallback",
    }


def build_modelfile(gguf_filename: str, resolved: dict, system_prompt: str = "",
                    num_ctx: int = 32768) -> str:
    """Build a complete Ollama Modelfile string."""
    lines = [f"FROM ./{gguf_filename}", ""]

    if resolved["source"] != "generic_fallback":
        lines.append(f'TEMPLATE """{resolved["template"]}"""')
        lines.append("")

    if system_prompt:
        lines.append(f'SYSTEM """{system_prompt}"""')
        lines.append("")

    lines.append(f"PARAMETER temperature 0.3")
    lines.append(f"PARAMETER top_p 0.9")
    lines.append(f"PARAMETER num_ctx {num_ctx}")

    for stop in resolved["stop_tokens"]:
        lines.append(f'PARAMETER stop "{stop}"')

    return "\n".join(lines)


def main():
    parser = argparse.ArgumentParser(description="Resolve Ollama template for a model")
    parser.add_argument("--model-path", help="Path to merged model directory")
    parser.add_argument("--model-type", help="Model type override (qwen2, lfm2, llama, etc.)")
    parser.add_argument("--build-modelfile", help="GGUF filename to build a complete Modelfile for")
    parser.add_argument("--system-prompt", default="", help="System prompt for Modelfile")
    parser.add_argument("--num-ctx", type=int, default=32768, help="Context window size")
    parser.add_argument("--output", help="Write Modelfile to this path instead of stdout")
    args = parser.parse_args()

    resolved = resolve(model_path=args.model_path, model_type=args.model_type)

    if args.build_modelfile:
        modelfile = build_modelfile(
            args.build_modelfile, resolved,
            system_prompt=args.system_prompt,
            num_ctx=args.num_ctx,
        )
        if args.output:
            with open(args.output, "w") as f:
                f.write(modelfile)
            print(f"Wrote Modelfile to {args.output} (source: {resolved['source']}, type: {resolved['model_type']})")
        else:
            print(modelfile)
    else:
        print(f"Model type: {resolved['model_type']}")
        print(f"Source: {resolved['source']}")
        print(f"Stop tokens: {resolved['stop_tokens']}")
        print(f"Template length: {len(resolved['template'])} chars")
        if resolved['template']:
            print(f"Template preview:\n{resolved['template'][:200]}...")


if __name__ == "__main__":
    main()
