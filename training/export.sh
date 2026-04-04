#!/bin/bash
# Export merged model to GGUF and import into Ollama.
#
# Usage: ./export.sh <model_name> <merged_model_dir> <gguf_output_dir> [quantization] [system_prompt]
#
# Args:
#   model_name       - Name for the Ollama model (e.g. "php-expert")
#   merged_model_dir - Path to merged model from train.py
#   gguf_output_dir  - Where to save GGUF files
#   quantization     - Quantization type (default: Q8_0)
#   system_prompt    - System prompt for the Modelfile

set -e

MODEL_NAME="${1:?Usage: export.sh <model_name> <merged_model_dir> <gguf_dir> [quant] [system_prompt]}"
MERGED_MODEL="${2:?Missing merged_model_dir}"
GGUF_DIR="${3:?Missing gguf_output_dir}"
QUANT="${4:-Q8_0}"
SYSTEM_PROMPT="${5:-You are a helpful coding assistant. You write clean, efficient, production-ready code.}"

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
LLAMA_CPP_DIR="${LLAMA_CPP_DIR:-$SCRIPT_DIR/tools/llama.cpp}"

# Use venv Python if available (has transformers, torch, etc.)
PYTHON="${HALFBAKED_PYTHON:-}"
if [ -z "$PYTHON" ] && [ -f "$SCRIPT_DIR/.venv/bin/python3" ]; then
    PYTHON="$SCRIPT_DIR/.venv/bin/python3"
else
    PYTHON="${PYTHON:-python3}"
fi

echo "=== HalfBaked Distillery - GGUF Export ==="
echo "Model: $MODEL_NAME"
echo "Quantization: $QUANT"
echo ""

# Check merged model exists
if [ ! -d "$MERGED_MODEL" ]; then
    echo "ERROR: Merged model not found at $MERGED_MODEL"
    echo "Run train.py first!"
    exit 1
fi

# Get llama.cpp if needed
if [ ! -d "$LLAMA_CPP_DIR" ]; then
    echo "Cloning llama.cpp..."
    mkdir -p "$(dirname "$LLAMA_CPP_DIR")"
    git clone --depth 1 https://github.com/ggerganov/llama.cpp "$LLAMA_CPP_DIR"
    echo "Building llama.cpp..."
    cd "$LLAMA_CPP_DIR"
    cmake -B build -DGGML_CUDA=ON 2>/dev/null || cmake -B build
    cmake --build build -j$(nproc)
    cd "$SCRIPT_DIR"
fi

mkdir -p "$GGUF_DIR"

# Step 1: Convert to FP16 GGUF
echo ""
echo ">>> Converting to FP16 GGUF..."
$PYTHON "$LLAMA_CPP_DIR/convert_hf_to_gguf.py" \
    "$MERGED_MODEL" \
    --outfile "$GGUF_DIR/${MODEL_NAME}-f16.gguf" \
    --outtype f16

# Step 2: Quantize
echo ""
echo ">>> Quantizing to $QUANT..."
"$LLAMA_CPP_DIR/build/bin/llama-quantize" \
    "$GGUF_DIR/${MODEL_NAME}-f16.gguf" \
    "$GGUF_DIR/${MODEL_NAME}-${QUANT}.gguf" \
    "$QUANT"

# Step 3: Create Ollama Modelfile
MODELFILE="$GGUF_DIR/Modelfile"

# Check for Ollama Go-template override (preserves tool calling)
# Place a file named ollama_template.txt next to the merged model to use it.
# If not found, detect Qwen models and use their standard tool-calling template.
OLLAMA_TEMPLATE_FILE="$MERGED_MODEL/ollama_template.txt"
MODEL_CONFIG="$MERGED_MODEL/config.json"

# Write the FROM line
echo "FROM ./${MODEL_NAME}-${QUANT}.gguf" > "$MODELFILE"
echo "" >> "$MODELFILE"

if [ -f "$OLLAMA_TEMPLATE_FILE" ]; then
    echo "Found custom Ollama template — embedding in Modelfile"
    echo 'TEMPLATE """' >> "$MODELFILE"
    cat "$OLLAMA_TEMPLATE_FILE" >> "$MODELFILE"
    echo '"""' >> "$MODELFILE"
    echo "" >> "$MODELFILE"
elif [ -f "$MODEL_CONFIG" ] && grep -qi '"qwen2"' "$MODEL_CONFIG" 2>/dev/null; then
    echo "Detected Qwen2 model — embedding tool-calling template"
    cat >> "$MODELFILE" << 'TMPLEOF'
TEMPLATE """{{- if .Messages }}
{{- if or .System .Tools }}<|im_start|>system
{{- if .System }}
{{ .System }}
{{- end }}
{{- if .Tools }}

# Tools

You may call one or more functions to assist with the user query.

You are provided with function signatures within <tools></tools> XML tags:
<tools>
{{- range .Tools }}
{"type": "function", "function": {{ .Function }}}
{{- end }}
</tools>

For each function call, return a json object with function name and arguments within <tool_call></tool_call> XML tags:
<tool_call>
{"name": <function-name>, "arguments": <args-json-object>}
</tool_call>
{{- end }}<|im_end|>
{{ end }}
{{- range $i, $_ := .Messages }}
{{- $last := eq (len (slice $.Messages $i)) 1 -}}
{{- if eq .Role "user" }}<|im_start|>user
{{ .Content }}<|im_end|>
{{ else if eq .Role "assistant" }}<|im_start|>assistant
{{ if .Content }}{{ .Content }}
{{- else if .ToolCalls }}<tool_call>
{{ range .ToolCalls }}{"name": "{{ .Function.Name }}", "arguments": {{ .Function.Arguments }}}
{{ end }}</tool_call>
{{- end }}{{ if not $last }}<|im_end|>
{{ end }}
{{- else if eq .Role "tool" }}<|im_start|>user
<tool_response>
{{ .Content }}
</tool_response><|im_end|>
{{ end }}
{{- if and (ne .Role "assistant") $last }}<|im_start|>assistant
{{ end }}
{{- end }}
{{- else }}
{{- if .System }}<|im_start|>system
{{ .System }}<|im_end|>
{{ end }}{{ if .Prompt }}<|im_start|>user
{{ .Prompt }}<|im_end|>
{{ end }}<|im_start|>assistant
{{ end }}{{ .Response }}{{ if .Response }}<|im_end|>{{ end }}"""

TMPLEOF
fi

cat >> "$MODELFILE" << MFEOF
SYSTEM """${SYSTEM_PROMPT}"""

PARAMETER temperature 0.3
PARAMETER top_p 0.9
PARAMETER num_ctx 4096
PARAMETER stop "<|im_end|>"
PARAMETER stop "<|im_start|>"
MFEOF

echo ""
echo ">>> Importing into Ollama..."
cd "$GGUF_DIR"
ollama create "$MODEL_NAME" -f Modelfile

# Clean up FP16 intermediate
echo "Cleaning up FP16 intermediate..."
rm -f "$GGUF_DIR/${MODEL_NAME}-f16.gguf"

GGUF_PATH="$GGUF_DIR/${MODEL_NAME}-${QUANT}.gguf"
GGUF_SIZE=$(stat -c%s "$GGUF_PATH" 2>/dev/null || stat -f%z "$GGUF_PATH" 2>/dev/null || echo "0")

echo ""
echo "=== Done! ==="
echo ""
echo "GGUF_PATH: $GGUF_PATH"
echo "GGUF_SIZE: $GGUF_SIZE"
echo "Model registered as: $MODEL_NAME"
echo ""
echo "Test it:"
echo "  ollama run $MODEL_NAME 'Write a hello world'"
echo ""
echo "Use with HalfBaked:"
echo "  halfbaked generate --model=$MODEL_NAME \"Your task here\""
