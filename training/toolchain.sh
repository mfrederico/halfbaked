#!/bin/bash
#
# HalfBaked Training Toolchain
# End-to-end pipeline: extract → distill → train (SFT+DPO) → export → register
#
# Usage:
#   ./training/toolchain.sh --name myctobot-expert \
#     --sources /path/to/project1,/path/to/project2 \
#     --target 500
#
#   ./training/toolchain.sh --name myctobot-expert \
#     --sources /home/user/myctobot,/home/user/cannonwms \
#     --base-model 7b-tools \
#     --target 1000 \
#     --tool-ratio 0.2 \
#     --quantization Q8_0
#
#   # Resume from a specific stage
#   ./training/toolchain.sh --name myctobot-expert --resume train
#
#   # Skip DPO (SFT only)
#   ./training/toolchain.sh --name myctobot-expert --sources /path/to/project --sft-only
#
# Stages: extract → distill → train → harvest → dpo → export → register
#
# Requirements:
#   - Run training/setup-venv.sh first
#   - ANTHROPIC_API_KEY or conf/anthropic.ini
#   - NVIDIA GPU with CUDA
#   - Ollama running locally

set -e

# ─── Defaults ────────────────────────────────────────────────────────────────
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
VENV_PYTHON="$SCRIPT_DIR/.venv/bin/python3"

MODEL_NAME=""
SOURCES=""
BASE_MODEL="3b"
TARGET_EXAMPLES=2000
TOOL_RATIO=0.2
BATCH_SIZE=5
EPOCHS=3
DPO_EPOCHS=1
MAX_SEQ_LENGTH=2048
LORA_RANK=32
GRAD_ACCUM=16
QUANTIZATION="Q8_0"
NUM_CTX=32768
SFT_ONLY=false
RESUME=""
WORK_DIR=""
SYSTEM_PROMPT=""
DPO_CANDIDATES=3
DISTILL_MODEL="claude-sonnet-4-20250514"
DISTILL_PROVIDER="anthropic"
DISTILL_API_BASE="http://127.0.0.1:11434/v1"
TOOL_DATA_COUNT=0
WORKERS=8
DISTILL_ROUNDS=1

# ─── Parse arguments ─────────────────────────────────────────────────────────
while [[ $# -gt 0 ]]; do
    case "$1" in
        --name)           MODEL_NAME="$2"; shift 2 ;;
        --sources)        SOURCES="$2"; shift 2 ;;
        --base-model)     BASE_MODEL="$2"; shift 2 ;;
        --target)         TARGET_EXAMPLES="$2"; shift 2 ;;
        --tool-ratio)     TOOL_RATIO="$2"; shift 2 ;;
        --batch-size)     BATCH_SIZE="$2"; shift 2 ;;
        --epochs)         EPOCHS="$2"; shift 2 ;;
        --dpo-epochs)     DPO_EPOCHS="$2"; shift 2 ;;
        --max-seq-length) MAX_SEQ_LENGTH="$2"; shift 2 ;;
        --lora-rank)      LORA_RANK="$2"; shift 2 ;;
        --quantization)   QUANTIZATION="$2"; shift 2 ;;
        --num-ctx)        NUM_CTX="$2"; shift 2 ;;
        --sft-only)       SFT_ONLY=true; shift ;;
        --resume)         RESUME="$2"; shift 2 ;;
        --work-dir)       WORK_DIR="$2"; shift 2 ;;
        --system-prompt)  SYSTEM_PROMPT="$2"; shift 2 ;;
        --dpo-candidates) DPO_CANDIDATES="$2"; shift 2 ;;
        --distill-model)  DISTILL_MODEL="$2"; shift 2 ;;
        --distill-provider) DISTILL_PROVIDER="$2"; shift 2 ;;
        --distill-api-base) DISTILL_API_BASE="$2"; shift 2 ;;
        --tool-data)      TOOL_DATA_COUNT="$2"; shift 2 ;;
        --workers)        WORKERS="$2"; shift 2 ;;
        --distill-rounds) DISTILL_ROUNDS="$2"; shift 2 ;;
        -h|--help)
            echo "HalfBaked Training Toolchain"
            echo ""
            echo "Usage: toolchain.sh --name <model-name> --sources <path1,path2,...> [options]"
            echo ""
            echo "Required:"
            echo "  --name <name>           Model name for Ollama (e.g., myctobot-expert)"
            echo "  --sources <paths>       Comma-separated project paths to train on"
            echo ""
            echo "Training options:"
            echo "  --base-model <model>    Base model: 0.5b, 1.5b, 3b, 7b, 7b-tools, 14b (default: 7b-tools)"
            echo "  --target <n>            Target training examples (default: 500)"
            echo "  --tool-ratio <ratio>    Fraction of tool-calling examples (default: 0.2)"
            echo "  --epochs <n>            SFT training epochs (default: 3)"
            echo "  --dpo-epochs <n>        DPO training epochs (default: 1)"
            echo "  --max-seq-length <n>    Max sequence length (default: 1536)"
            echo "  --lora-rank <n>         LoRA rank (default: 32)"
            echo "  --sft-only              Skip DPO stage"
            echo ""
            echo "Export options:"
            echo "  --quantization <type>   GGUF quantization: Q4_K_M, Q5_K_M, Q8_0 (default: Q8_0)"
            echo "  --num-ctx <n>           Ollama context window (default: 32768)"
            echo ""
            echo "Data generation:"
            echo "  --distill-model <model> Claude model for dataset generation (default: claude-sonnet-4-20250514)"
            echo "                          Use claude-haiku-4-5-20251001 for cheaper/faster generation"
            echo "  --tool-data <n>         Extra tool-use examples via generate_tool_data.py (default: 0)"
            echo "                          Recommended: 20-30% of --target (e.g., --target 5000 --tool-data 1000)"
            echo "  --workers <n>           Parallel API workers for generation (default: 8)"
            echo "  --distill-rounds <n>    Run distillation N times to accumulate diverse examples (default: 1)"
            echo ""
            echo "Other:"
            echo "  --resume <stage>        Resume from: extract, distill, train, harvest, dpo, export"
            echo "  --work-dir <dir>        Override work directory"
            echo "  --system-prompt <text>  Custom system prompt for the model"
            echo "  --dpo-candidates <n>    Candidates per prompt for synthetic DPO (default: 3)"
            exit 0
            ;;
        *)
            echo "Unknown option: $1"
            exit 1
            ;;
    esac
done

# ─── Validate ─────────────────────────────────────────────────────────────────
if [ -z "$MODEL_NAME" ]; then
    echo "ERROR: --name is required"
    exit 1
fi

if [ -z "$SOURCES" ] && [ -z "$RESUME" ]; then
    echo "ERROR: --sources is required (comma-separated paths)"
    exit 1
fi

# Expand ~ and validate source paths
if [ -n "$SOURCES" ]; then
    EXPANDED_SOURCES=""
    IFS=',' read -ra _SRC_CHECK <<< "$SOURCES"
    for src in "${_SRC_CHECK[@]}"; do
        src=$(echo "$src" | xargs)  # Trim whitespace
        src="${src/#\~/$HOME}"       # Expand ~
        if [ ! -d "$src" ]; then
            echo "ERROR: Source directory not found: $src"
            exit 1
        fi
        if [ -n "$EXPANDED_SOURCES" ]; then
            EXPANDED_SOURCES="$EXPANDED_SOURCES,$src"
        else
            EXPANDED_SOURCES="$src"
        fi
    done
    SOURCES="$EXPANDED_SOURCES"
fi

if [ ! -f "$VENV_PYTHON" ]; then
    echo "ERROR: Training venv not found. Run: ./training/setup-venv.sh"
    exit 1
fi

# Load API key
if [ -z "$ANTHROPIC_API_KEY" ]; then
    if [ -f "$PROJECT_DIR/conf/anthropic.ini" ]; then
        ANTHROPIC_API_KEY=$(grep -oP 'anthropic_key=\K.*' "$PROJECT_DIR/conf/anthropic.ini")
        export ANTHROPIC_API_KEY
    fi
fi

if [ -z "$ANTHROPIC_API_KEY" ]; then
    echo "ERROR: No ANTHROPIC_API_KEY found. Set env var or create conf/anthropic.ini"
    exit 1
fi

# Work directory
if [ -z "$WORK_DIR" ]; then
    WORK_DIR="$PROJECT_DIR/data/toolchain/$MODEL_NAME"
fi
mkdir -p "$WORK_DIR"

# Default system prompt
if [ -z "$SYSTEM_PROMPT" ]; then
    SYSTEM_PROMPT="You are an expert PHP coding assistant. You write complete, production-ready PHP code using FlightPHP for routing and RedBeanPHP for database operations. You use modern PHP 8.x features. You never write placeholder code or pseudocode — every response is runnable."
fi

# ─── Logging ──────────────────────────────────────────────────────────────────
LOG_FILE="$WORK_DIR/toolchain.log"

log() {
    local msg="[$(date '+%H:%M:%S')] $1"
    echo "$msg"
    echo "$msg" >> "$LOG_FILE"
}

log_section() {
    echo ""
    echo "════════════════════════════════════════════════════════════════"
    echo "  $1"
    echo "════════════════════════════════════════════════════════════════"
    echo ""
    log ">>> $1"
}

# Save config for resume
cat > "$WORK_DIR/toolchain_config.json" << CFGEOF
{
    "model_name": "$MODEL_NAME",
    "sources": "$SOURCES",
    "base_model": "$BASE_MODEL",
    "target_examples": $TARGET_EXAMPLES,
    "tool_ratio": $TOOL_RATIO,
    "epochs": $EPOCHS,
    "dpo_epochs": $DPO_EPOCHS,
    "max_seq_length": $MAX_SEQ_LENGTH,
    "lora_rank": $LORA_RANK,
    "quantization": "$QUANTIZATION",
    "num_ctx": $NUM_CTX,
    "sft_only": $SFT_ONLY,
    "system_prompt": "$SYSTEM_PROMPT"
}
CFGEOF

# ─── Determine starting stage ────────────────────────────────────────────────
STAGES=(extract distill train harvest dpo export register)
START_STAGE=0

if [ -n "$RESUME" ]; then
    for i in "${!STAGES[@]}"; do
        if [ "${STAGES[$i]}" = "$RESUME" ]; then
            START_STAGE=$i
            log "Resuming from stage: $RESUME"
            break
        fi
    done
fi

should_run() {
    local stage_name="$1"
    for i in "${!STAGES[@]}"; do
        if [ "${STAGES[$i]}" = "$stage_name" ] && [ "$i" -ge "$START_STAGE" ]; then
            return 0
        fi
    done
    return 1
}

# ─── Stage 1: Extract ────────────────────────────────────────────────────────
if should_run "extract"; then
    log_section "Stage 1/7: Extract code samples"

    IFS=',' read -ra SOURCE_ARRAY <<< "$SOURCES"
    COMBINED_SAMPLES="$WORK_DIR/code_samples.jsonl"
    > "$COMBINED_SAMPLES"  # Clear

    for src in "${SOURCE_ARRAY[@]}"; do
        src=$(echo "$src" | xargs)  # Trim whitespace
        src_name=$(basename "$src")
        log "Extracting from: $src ($src_name)"

        SAMPLE_FILE="$WORK_DIR/samples_${src_name}.jsonl"
        $VENV_PYTHON "$SCRIPT_DIR/extract.py" \
            --project-path "$src" \
            --output "$SAMPLE_FILE" \
            --language php 2>&1 | tee -a "$LOG_FILE"

        cat "$SAMPLE_FILE" >> "$COMBINED_SAMPLES"
        log "  $(wc -l < "$SAMPLE_FILE") samples from $src_name"
    done

    TOTAL_SAMPLES=$(wc -l < "$COMBINED_SAMPLES")
    log "Total code samples: $TOTAL_SAMPLES"
fi

# ─── Stage 2: Distill ────────────────────────────────────────────────────────
if should_run "distill"; then
    log_section "Stage 2/7: Generate training dataset via Claude"

    DATASET_FILE="$WORK_DIR/dataset.jsonl"

    # Build distill config
    cat > "$WORK_DIR/distill_config.json" << DISTEOF
{
    "samples_file": "$WORK_DIR/code_samples.jsonl",
    "output_file": "$DATASET_FILE",
    "api_key": "$ANTHROPIC_API_KEY",
    "system_prompt": "You are generating training data for a specialized PHP AI coding assistant.\nThe assistant writes production-ready PHP using FlightPHP (routing, middleware), RedBeanPHP (ORM/database), and modern PHP 8.x patterns.\n\nCRITICAL RULES for every response you generate:\n1. Every code response MUST be complete, runnable PHP — no pseudocode, no placeholders, no '// ...' shortcuts\n2. Use RedBeanPHP (R::find, R::store, R::dispense, R::findOne, R::exec) for ALL database operations — never raw PDO or query builders\n3. Use FlightPHP (Flight::route, Flight::json, Flight::request, Flight::halt) for ALL HTTP routing — never echo or raw headers\n4. Include proper error handling with try/catch and Flight::halt() for error responses\n5. Use PHP 8.x features: named arguments, match expressions, readonly properties, enums, union types, null-safe operator\n6. Responses must be the kind of code a senior developer would merge without changes",
    "training_system_prompt": "$SYSTEM_PROMPT",
    "target_examples": $TARGET_EXAMPLES,
    "batch_size": $BATCH_SIZE,
    "prompt_templates": [
        {"category": "generate", "prompt": "Study this PHP code from a real production project. Generate {n} instruction-response pairs where the instruction asks to write NEW functionality that follows the same patterns, and the response is COMPLETE working PHP code.\n\nThe response code MUST:\n- Be fully functional (no placeholders or '...')\n- Use the same frameworks visible in the code (FlightPHP for routes, RedBeanPHP for data)\n- Include proper use/namespace statements\n- Handle errors appropriately\n\nCode from \`{file}\`:\n\`\`\`php\n{code}\n\`\`\`\n\nReturn a JSON array of objects with \"instruction\" and \"response\" fields. The response MUST be complete PHP code."},
        {"category": "generate_variation", "prompt": "Given this PHP code, generate {n} instruction-response pairs where the user asks to build something SIMILAR but for a different domain (e.g., if the code manages users, generate code that manages products or orders using the same patterns).\n\nEach response must be COMPLETE runnable PHP code using the same framework patterns as the original.\n\nCode from \`{file}\`:\n\`\`\`php\n{code}\n\`\`\`\n\nReturn a JSON array of objects with \"instruction\" and \"response\" fields."},
        {"category": "refactor", "prompt": "Given this PHP code, generate {n} instruction-response pairs where the user asks to improve or refactor the code. The response should provide the COMPLETE improved version with explanations of what changed and why.\n\nFocus on: modern PHP 8.x features, better error handling, cleaner RedBeanPHP usage, proper FlightPHP middleware patterns.\n\nCode from \`{file}\`:\n\`\`\`php\n{code}\n\`\`\`\n\nReturn a JSON array of objects with \"instruction\" and \"response\" fields."},
        {"category": "debug", "prompt": "Given this PHP code, generate {n} instruction-response pairs about realistic bugs and how to fix them. The instruction should describe a symptom (error message, wrong behavior), and the response should diagnose the cause AND provide the COMPLETE fixed code.\n\nCode from \`{file}\`:\n\`\`\`php\n{code}\n\`\`\`\n\nReturn a JSON array of objects with \"instruction\" and \"response\" fields."},
        {"category": "extend", "prompt": "Given this PHP code, generate {n} instruction-response pairs where the user asks to ADD a specific feature. The response must provide COMPLETE working code that integrates with the existing patterns.\n\nExamples: add pagination, add search/filtering, add validation, add caching, add middleware, add API authentication.\n\nCode from \`{file}\`:\n\`\`\`php\n{code}\n\`\`\`\n\nReturn a JSON array of objects with \"instruction\" and \"response\" fields."},
        {"category": "test", "prompt": "Given this PHP code, generate {n} instruction-response pairs where the user asks to write tests. The response must provide COMPLETE PHPUnit test code that actually tests the functionality.\n\nCode from \`{file}\`:\n\`\`\`php\n{code}\n\`\`\`\n\nReturn a JSON array of objects with \"instruction\" and \"response\" fields."}
    ],
    "standalone_prompts": [
        {"category": "flight_routes", "prompt": "Generate {n} instruction-response pairs where the user asks to build FlightPHP API endpoints. Each response must be COMPLETE working PHP code with:\n- Flight::route() definitions with proper HTTP methods\n- Request validation using Flight::request()\n- RedBeanPHP for database operations (R::find, R::store, R::dispense)\n- JSON responses via Flight::json()\n- Error handling with Flight::halt()\n\nVary the domains: user management, product catalog, order processing, inventory, notifications, file uploads, authentication.\n\nReturn a JSON array of objects with \"instruction\" and \"response\" fields."},
        {"category": "redbean_models", "prompt": "Generate {n} instruction-response pairs about RedBeanPHP data modeling and queries. Each response must be COMPLETE working PHP code showing:\n- R::dispense() and R::store() for creating records\n- R::find() and R::findOne() with query conditions\n- R::exec() for complex queries\n- Bean relationships (ownList, sharedList, via)\n- FUSE models with custom methods\n- Transaction handling with R::begin/commit/rollback\n\nReturn a JSON array of objects with \"instruction\" and \"response\" fields."},
        {"category": "middleware", "prompt": "Generate {n} instruction-response pairs about FlightPHP middleware, request/response handling, and application structure. Each response must be COMPLETE working PHP code.\n\nTopics: authentication middleware, CORS handling, rate limiting, request logging, input sanitization, JSON API patterns, error handler registration, grouping routes.\n\nReturn a JSON array of objects with \"instruction\" and \"response\" fields."},
        {"category": "php_patterns", "prompt": "Generate {n} instruction-response pairs about modern PHP 8.x patterns applied to web development. Each response must be COMPLETE working PHP code.\n\nTopics: enums for status fields, readonly DTOs, named arguments, match expressions, fibers for async, attributes for validation, union types, null-safe operators, first-class callables.\n\nReturn a JSON array of objects with \"instruction\" and \"response\" fields."}
    ]
}
DISTEOF

    log "Target: $TARGET_EXAMPLES examples x $DISTILL_ROUNDS rounds (${TOOL_RATIO} tool-calling ratio)"
    log "Provider: Anthropic Claude ($DISTILL_MODEL)"

    POOL_DB="$PROJECT_DIR/data/training_pool.db"

    for ROUND in $(seq 1 "$DISTILL_ROUNDS"); do
        log "── Distill round $ROUND/$DISTILL_ROUNDS ──"

        # Clear progress file between rounds so distill.py generates fresh batches
        rm -f "$WORK_DIR/.generate_progress.json"

        # Round 1 writes fresh, subsequent rounds append
        RESUME_FLAG=""
        if [ "$ROUND" -gt 1 ]; then
            RESUME_FLAG="--resume"
        fi

        PROVIDER_FLAGS="--provider $DISTILL_PROVIDER"
        if [ "$DISTILL_PROVIDER" != "anthropic" ]; then
            PROVIDER_FLAGS="$PROVIDER_FLAGS --api-base $DISTILL_API_BASE"
        fi

        $VENV_PYTHON "$SCRIPT_DIR/distill.py" \
            --config "$WORK_DIR/distill_config.json" \
            --model "$DISTILL_MODEL" \
            $PROVIDER_FLAGS \
            --workers "$WORKERS" \
            --pool "$POOL_DB" \
            --pool-source "$MODEL_NAME" \
            $RESUME_FLAG \
            2>&1 | tee -a "$LOG_FILE"

        ROUND_COUNT=$(wc -l < "$DATASET_FILE" 2>/dev/null || echo "0")
        log "Round $ROUND complete: $ROUND_COUNT examples in dataset"
    done

    # Generate tool-use training data if requested
    if [ "$TOOL_DATA_COUNT" -gt 0 ]; then
        log "Generating $TOOL_DATA_COUNT tool-use training examples ($WORKERS workers)..."
        TOOL_FILE="$WORK_DIR/tool_training.jsonl"
        $VENV_PYTHON "$SCRIPT_DIR/generate_tool_data.py" \
            --output "$TOOL_FILE" \
            --count "$TOOL_DATA_COUNT" \
            --batch-size 5 \
            --model "$DISTILL_MODEL" \
            --workers "$WORKERS" \
            --pool "$POOL_DB" \
            --pool-source "$MODEL_NAME" \
            2>&1 | tee -a "$LOG_FILE"

        # Append tool data to main dataset
        if [ -f "$TOOL_FILE" ]; then
            cat "$TOOL_FILE" >> "$DATASET_FILE"
            TOOL_COUNT=$(wc -l < "$TOOL_FILE")
            log "Appended $TOOL_COUNT tool-use examples to dataset"
        fi
    fi

    # Clean bad JSON lines
    $VENV_PYTHON -c "
import json
good = []
with open('$DATASET_FILE') as f:
    for line in f:
        line = line.strip()
        if not line: continue
        try:
            json.loads(line)
            good.append(line)
        except: pass
with open('$DATASET_FILE', 'w') as f:
    for line in good:
        f.write(line + '\n')
print(f'Clean dataset: {len(good)} examples')
" 2>&1 | tee -a "$LOG_FILE"

    # Show pool stats after all rounds
    if [ -f "$POOL_DB" ]; then
        $VENV_PYTHON "$SCRIPT_DIR/pool.py" --db "$POOL_DB" stats 2>&1 | tee -a "$LOG_FILE"
    fi
fi

# ─── Stage 3: Train SFT ──────────────────────────────────────────────────────
if should_run "train"; then
    log_section "Stage 3/7: SFT Training"

    DATASET_FILE="$WORK_DIR/dataset.jsonl"
    OUTPUT_DIR="$WORK_DIR/output"
    POOL_DB="$PROJECT_DIR/data/training_pool.db"

    # Export full pool to training dataset (accumulates across all runs)
    if [ -f "$POOL_DB" ]; then
        log "Exporting training pool to dataset..."
        $VENV_PYTHON "$SCRIPT_DIR/pool.py" --db "$POOL_DB" export "$DATASET_FILE"
        $VENV_PYTHON "$SCRIPT_DIR/pool.py" --db "$POOL_DB" stats 2>&1 | tee -a "$LOG_FILE"
    fi

    # Unload Ollama models to free GPU
    log "Freeing GPU memory..."
    curl -s http://127.0.0.1:11434/api/generate -d "{\"model\":\"${MODEL_NAME}-sft:latest\",\"keep_alive\":0}" > /dev/null 2>&1 || true
    curl -s http://127.0.0.1:11434/api/generate -d "{\"model\":\"${MODEL_NAME}-dpo:latest\",\"keep_alive\":0}" > /dev/null 2>&1 || true
    sleep 3

    DATASET_COUNT=$(wc -l < "$DATASET_FILE")
    log "Training on $DATASET_COUNT examples"
    log "Base model: $BASE_MODEL | Epochs: $EPOCHS | Seq length: $MAX_SEQ_LENGTH | LoRA rank: $LORA_RANK"

    SFT_FLAG=""
    if [ "$SFT_ONLY" = true ]; then
        SFT_FLAG="--sft-only"
    fi

    set -o pipefail
    $VENV_PYTHON "$SCRIPT_DIR/train.py" \
        --dataset "$DATASET_FILE" \
        --output-dir "$OUTPUT_DIR" \
        --base-model "$BASE_MODEL" \
        --epochs "$EPOCHS" \
        --batch-size 1 \
        --no-packing \
        --max-seq-length "$MAX_SEQ_LENGTH" \
        --lora-rank "$LORA_RANK" \
        --gradient-accumulation-steps "$GRAD_ACCUM" \
        $SFT_FLAG \
        2>&1 | tee -a "$LOG_FILE"

    if [ ! -d "$OUTPUT_DIR/merged_model_sft" ]; then
        log "ERROR: Training failed — no merged model produced. Stopping."
        exit 1
    fi

    log "SFT training complete"
fi

# ─── Stage 4: Harvest DPO pairs ──────────────────────────────────────────────
if should_run "harvest" && [ "$SFT_ONLY" != true ]; then
    log_section "Stage 4/7: Harvest DPO preference pairs"

    DPO_FILE="$WORK_DIR/dpo_pairs.jsonl"
    SFT_MODEL_NAME="${MODEL_NAME}-sft"

    # Export a temporary SFT model for synthetic generation
    log "Exporting temporary SFT model for DPO harvesting..."
    LD_PRELOAD="" bash "$SCRIPT_DIR/export.sh" \
        "$SFT_MODEL_NAME" \
        "$WORK_DIR/output/merged_model_sft" \
        "$WORK_DIR/gguf-sft-tmp" \
        "$QUANTIZATION" \
        "$SYSTEM_PROMPT" 2>&1 | tee -a "$LOG_FILE"

    log "Generating synthetic DPO pairs ($DPO_CANDIDATES candidates per prompt)..."
    $VENV_PYTHON "$SCRIPT_DIR/harvest.py" \
        --sft-dataset "$WORK_DIR/dataset.jsonl" \
        --output "$DPO_FILE" \
        --synthetic \
        --model "$SFT_MODEL_NAME" \
        --num-candidates "$DPO_CANDIDATES" \
        2>&1 | tee -a "$LOG_FILE"

    DPO_COUNT=$(wc -l < "$DPO_FILE" 2>/dev/null || echo "0")
    log "Harvested $DPO_COUNT DPO pairs"

    # Clean up temporary GGUF
    rm -rf "$WORK_DIR/gguf-sft-tmp"
else
    if should_run "harvest"; then
        log "Skipping DPO harvest (--sft-only)"
    fi
fi

# ─── Stage 5: Train DPO ──────────────────────────────────────────────────────
if should_run "dpo" && [ "$SFT_ONLY" != true ]; then
    log_section "Stage 5/7: DPO Training"

    DPO_FILE="$WORK_DIR/dpo_pairs.jsonl"
    OUTPUT_DIR="$WORK_DIR/output"

    if [ ! -f "$DPO_FILE" ] || [ "$(wc -l < "$DPO_FILE")" -lt 5 ]; then
        log "Not enough DPO pairs (need ≥5). Skipping DPO."
    else
        # Unload Ollama models to free GPU
        curl -s http://127.0.0.1:11434/api/generate -d "{\"model\":\"${MODEL_NAME}-sft:latest\",\"keep_alive\":0}" > /dev/null 2>&1 || true
        sleep 3

        DPO_COUNT=$(wc -l < "$DPO_FILE")
        log "DPO training with $DPO_COUNT preference pairs"

        $VENV_PYTHON "$SCRIPT_DIR/train.py" \
            --dataset "$WORK_DIR/dataset.jsonl" \
            --dpo-dataset "$DPO_FILE" \
            --output-dir "$OUTPUT_DIR" \
            --base-model "$BASE_MODEL" \
            --epochs "$EPOCHS" \
            --dpo-epochs "$DPO_EPOCHS" \
            --batch-size 1 \
            --no-packing \
            --max-seq-length "$MAX_SEQ_LENGTH" \
            --lora-rank "$LORA_RANK" \
            --gradient-accumulation-steps "$GRAD_ACCUM" \
            2>&1 | tee -a "$LOG_FILE"

        log "DPO training complete"
    fi
else
    if should_run "dpo"; then
        log "Skipping DPO training (--sft-only)"
    fi
fi

# ─── Stage 6: Export GGUFs ───────────────────────────────────────────────────
if should_run "export"; then
    log_section "Stage 6/7: Export to GGUF"

    OUTPUT_DIR="$WORK_DIR/output"

    # Always export SFT
    log "Exporting SFT model..."
    LD_PRELOAD="" bash "$SCRIPT_DIR/export.sh" \
        "${MODEL_NAME}-sft" \
        "$OUTPUT_DIR/merged_model_sft" \
        "$WORK_DIR/gguf-sft" \
        "$QUANTIZATION" \
        "$SYSTEM_PROMPT" 2>&1 | tee -a "$LOG_FILE"

    # Export DPO if it exists
    if [ -d "$OUTPUT_DIR/merged_model" ] && [ "$SFT_ONLY" != true ]; then
        log "Exporting DPO model..."
        LD_PRELOAD="" bash "$SCRIPT_DIR/export.sh" \
            "${MODEL_NAME}-dpo" \
            "$OUTPUT_DIR/merged_model" \
            "$WORK_DIR/gguf-dpo" \
            "$QUANTIZATION" \
            "$SYSTEM_PROMPT" 2>&1 | tee -a "$LOG_FILE"
    fi
fi

# ─── Stage 7: Register in Ollama with tool template ─────────────────────────
if should_run "register"; then
    log_section "Stage 7/7: Register in Ollama"

    # Detect model config for template selection
    MODEL_CONFIG="$WORK_DIR/output/merged_model_sft/config.json"

    # Build the Modelfile with tool-calling template
    write_modelfile() {
        local gguf_dir="$1"
        local gguf_name="$2"
        local modelfile="$gguf_dir/Modelfile"

        echo "FROM ./${gguf_name}" > "$modelfile"
        echo "" >> "$modelfile"

        # Check if Qwen model — add tool-calling template
        if [ -f "$MODEL_CONFIG" ] && grep -qi '"qwen2"' "$MODEL_CONFIG" 2>/dev/null; then
            log "  Embedding Qwen2 tool-calling template"
            cat >> "$modelfile" << 'TMPLEOF'
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

        cat >> "$modelfile" << PARAMEOF
SYSTEM """${SYSTEM_PROMPT}"""

PARAMETER temperature 0.3
PARAMETER top_p 0.9
PARAMETER num_ctx ${NUM_CTX}
PARAMETER stop "<|im_end|>"
PARAMETER stop "<|im_start|>"
PARAMEOF
    }

    # Find GGUF files (handle varying name patterns from export.sh)
    find_gguf() {
        local dir="$1"
        ls "$dir"/*.gguf 2>/dev/null | head -1
    }

    # Register SFT
    SFT_GGUF_DIR="$WORK_DIR/gguf-sft"
    SFT_GGUF_PATH=$(find_gguf "$SFT_GGUF_DIR")
    if [ -n "$SFT_GGUF_PATH" ]; then
        SFT_GGUF_NAME=$(basename "$SFT_GGUF_PATH")
        write_modelfile "$SFT_GGUF_DIR" "$SFT_GGUF_NAME"
        cd "$SFT_GGUF_DIR"
        log "Registering ${MODEL_NAME}-sft from $SFT_GGUF_NAME..."
        ollama create "${MODEL_NAME}-sft" -f Modelfile 2>&1 | tee -a "$LOG_FILE"
        log "Registered: ${MODEL_NAME}-sft"
    else
        log "WARNING: No GGUF found in $SFT_GGUF_DIR"
    fi

    # Register DPO
    DPO_GGUF_DIR="$WORK_DIR/gguf-dpo"
    DPO_GGUF_PATH=$(find_gguf "$DPO_GGUF_DIR")
    if [ -n "$DPO_GGUF_PATH" ]; then
        DPO_GGUF_NAME=$(basename "$DPO_GGUF_PATH")
        write_modelfile "$DPO_GGUF_DIR" "$DPO_GGUF_NAME"
        cd "$DPO_GGUF_DIR"
        log "Registering ${MODEL_NAME}-dpo from $DPO_GGUF_NAME..."
        ollama create "${MODEL_NAME}-dpo" -f Modelfile 2>&1 | tee -a "$LOG_FILE"
        log "Registered: ${MODEL_NAME}-dpo"
    fi

    # Register primary model name (points to best available: DPO > SFT)
    BEST_DIR="$DPO_GGUF_DIR"
    BEST_NAME="${MODEL_NAME}-dpo"
    if [ -z "$DPO_GGUF_PATH" ] || [ "$SFT_ONLY" = true ]; then
        BEST_DIR="$SFT_GGUF_DIR"
        BEST_NAME="${MODEL_NAME}-sft"
    fi
    BEST_GGUF=$(find_gguf "$BEST_DIR")
    if [ -n "$BEST_GGUF" ]; then
        BEST_GGUF_NAME=$(basename "$BEST_GGUF")
        write_modelfile "$BEST_DIR" "$BEST_GGUF_NAME"
        cd "$BEST_DIR"
        log "Registering ${MODEL_NAME} (alias for $BEST_NAME)..."
        ollama create "${MODEL_NAME}" -f Modelfile 2>&1 | tee -a "$LOG_FILE"
        log "Registered: ${MODEL_NAME}"
    fi
fi

# ─── Summary ──────────────────────────────────────────────────────────────────
cd "$PROJECT_DIR"

echo ""
echo "════════════════════════════════════════════════════════════════"
echo "  Training Complete!"
echo "════════════════════════════════════════════════════════════════"
echo ""
echo "  Model:          $MODEL_NAME"
echo "  Base:           $BASE_MODEL"
echo "  Work dir:       $WORK_DIR"
echo "  Dataset:        $(wc -l < "$WORK_DIR/dataset.jsonl" 2>/dev/null || echo '?') examples"

if [ -f "$WORK_DIR/dpo_pairs.jsonl" ]; then
    echo "  DPO pairs:      $(wc -l < "$WORK_DIR/dpo_pairs.jsonl") pairs"
fi

echo "  Quantization:   $QUANTIZATION"
echo "  Context window:  $NUM_CTX"
echo ""
echo "  Models available:"
ollama list 2>/dev/null | grep "$MODEL_NAME" || echo "    (none registered)"
echo ""
echo "  Test with:"
echo "    ollama run ${MODEL_NAME} 'Write a hello world in PHP'"
echo ""
echo "  All variants:"
echo "    ollama run ${MODEL_NAME}      # Best available (DPO > SFT)"
echo "    ollama run ${MODEL_NAME}-sft  # SFT only"
if [ "$SFT_ONLY" != true ]; then
    echo "    ollama run ${MODEL_NAME}-dpo  # SFT + DPO"
fi
echo ""
echo "  Use with Claude Code:"
echo "    claude --model ollama:${MODEL_NAME}"
echo ""
echo "  Resume from any stage:"
echo "    ./training/toolchain.sh --name $MODEL_NAME --resume <stage>"
echo "    Stages: extract, distill, train, harvest, dpo, export, register"
echo ""

log "Toolchain complete for $MODEL_NAME"
