# Training Toolchain Examples

## Quick Start — Single Codebase (SFT only, fastest)

```bash
./training/toolchain.sh --name myctobot-expert \
  --sources /home/mfrederico/development/myctobot \
  --target 300 \
  --sft-only
```

## Standard — Multiple Codebases with DPO

```bash
./training/toolchain.sh --name myctobot-expert \
  --sources /home/mfrederico/development/myctobot,/home/mfrederico/development/cannonwms \
  --target 500
```

## Full — Large Dataset, Tool Calling, High Quality

```bash
./training/toolchain.sh --name myctobot-expert \
  --sources /home/mfrederico/development/myctobot,/home/mfrederico/development/cannonwms \
  --base-model 7b-tools \
  --target 1000 \
  --tool-ratio 0.2 \
  --epochs 3 \
  --dpo-epochs 1 \
  --max-seq-length 1536 \
  --lora-rank 32 \
  --quantization Q8_0 \
  --num-ctx 32768
```

## Small Model — Faster Training, Less VRAM

```bash
./training/toolchain.sh --name myctobot-lite \
  --sources /home/mfrederico/development/myctobot \
  --base-model 3b \
  --target 300 \
  --max-seq-length 2048 \
  --lora-rank 64 \
  --quantization Q8_0 \
  --sft-only
```

## Resume — Pick Up Where You Left Off

```bash
# Resume from training (skip extract + distill)
./training/toolchain.sh --name myctobot-expert --resume train

# Resume from export (skip everything, just re-export)
./training/toolchain.sh --name myctobot-expert --resume export

# Resume from register (just re-register in Ollama with new settings)
./training/toolchain.sh --name myctobot-expert --resume register --num-ctx 65536
```

## Available Stages

```
extract   → Extract code samples from source projects
distill   → Generate training dataset via Claude API
train     → SFT fine-tuning with QLoRA
harvest   → Generate DPO preference pairs from SFT model
dpo       → DPO preference optimization training
export    → Convert to GGUF and quantize
register  → Register in Ollama with tool-calling template
```

## Base Models (RTX 4090 / 24GB VRAM)

| Model | Flag | Tool Calling | Max Seq Length | Notes |
|-------|------|-------------|----------------|-------|
| Qwen2.5-Coder-3B | `--base-model 3b` | Weak | 2048 | Fast training, small output |
| Qwen2.5-Coder-7B | `--base-model 7b` | Moderate | 1536 | Good for code, no tools |
| Qwen2.5-7B-Instruct | `--base-model 7b-tools` | Strong | 1536 | Best for tool calling |
| Qwen2.5-Coder-14B | `--base-model 14b` | Moderate | ~512 | Needs cloud GPU for training |

## Tips

- **First run**: Start with `--target 300 --sft-only` to validate the pipeline fast
- **Tool calling**: Use `--base-model 7b-tools` — the non-Coder Instruct model has native tool support
- **Context window**: `--num-ctx 32768` is recommended for Claude Code (its system prompt uses ~6K tokens)
- **VRAM issues**: Lower `--max-seq-length` or `--lora-rank` if you get OOM errors
- **Better quality**: More data helps — `--target 1000` with multiple `--sources`
- **Compare models**: Both SFT and DPO variants are registered, test both
