#!/usr/bin/env python3
"""
Multi-language code extractor for HalfBaked Distillery.
Extracts code samples from a project for training data generation.

Usage:
  python3 extract.py --config config.json
  python3 extract.py --project-path /path/to/project --output samples.jsonl --language php
"""

import argparse
import json
import os
import re
import sys
from pathlib import Path


def load_config(config_path: str) -> dict:
    with open(config_path) as f:
        return json.loads(f.read())


# --- Metadata Extractors ---

def extract_php_metadata(filepath: Path, content: str) -> dict:
    namespace = ""
    class_name = ""
    uses = []
    extends = ""
    implements = []

    for line in content.split("\n"):
        line = line.strip()
        if line.startswith("namespace "):
            namespace = line.replace("namespace ", "").rstrip(";")
        elif line.startswith("use "):
            uses.append(line.replace("use ", "").rstrip(";"))
        elif m := re.match(r"class\s+(\w+)(?:\s+extends\s+(\w+))?(?:\s+implements\s+(.+))?", line):
            class_name = m.group(1)
            extends = m.group(2) or ""
            if m.group(3):
                implements = [i.strip() for i in m.group(3).rstrip("{").split(",")]

    return {
        "file": str(filepath),
        "namespace": namespace,
        "class": class_name,
        "extends": extends,
        "implements": implements,
        "uses": uses,
    }


def extract_python_metadata(filepath: Path, content: str) -> dict:
    imports = []
    classes = []
    functions = []
    decorators = []

    for line in content.split("\n"):
        line = line.strip()
        if line.startswith("import ") or line.startswith("from "):
            imports.append(line)
        elif line.startswith("class "):
            if m := re.match(r"class\s+(\w+)", line):
                classes.append(m.group(1))
        elif line.startswith("def ") or line.startswith("async def "):
            if m := re.match(r"(?:async\s+)?def\s+(\w+)", line):
                functions.append(m.group(1))
        elif line.startswith("@"):
            decorators.append(line)

    return {
        "file": str(filepath),
        "imports": imports,
        "classes": classes,
        "functions": functions,
        "decorators": decorators[:20],
    }


def extract_javascript_metadata(filepath: Path, content: str) -> dict:
    imports = []
    exports = []
    classes = []
    functions = []

    for line in content.split("\n"):
        line = line.strip()
        if line.startswith("import "):
            imports.append(line)
        if "export " in line:
            exports.append(line[:100])
        if m := re.match(r"(?:export\s+)?class\s+(\w+)", line):
            classes.append(m.group(1))
        if m := re.match(r"(?:export\s+)?(?:async\s+)?function\s+(\w+)", line):
            functions.append(m.group(1))
        if m := re.match(r"(?:export\s+)?(?:const|let|var)\s+(\w+)\s*=", line):
            functions.append(m.group(1))

    return {
        "file": str(filepath),
        "imports": imports,
        "exports": exports[:20],
        "classes": classes,
        "functions": functions,
    }


def extract_css_metadata(filepath: Path, content: str) -> dict:
    selectors = []
    media_queries = []
    variables = []

    for line in content.split("\n"):
        line = line.strip()
        if line.startswith("@media"):
            media_queries.append(line[:100])
        elif line.startswith("--"):
            variables.append(line.split(":")[0])
        elif re.match(r"^[.#@\w\[\*]", line) and "{" in line:
            sel = line.split("{")[0].strip()
            if sel:
                selectors.append(sel)

    return {
        "file": str(filepath),
        "selectors": selectors[:50],
        "media_queries": media_queries,
        "variables": variables[:30],
    }


METADATA_EXTRACTORS = {
    "php": extract_php_metadata,
    "python": extract_python_metadata,
    "javascript": extract_javascript_metadata,
    "css": extract_css_metadata,
}

# Default function/method patterns per language
DEFAULT_FUNCTION_PATTERNS = {
    "php": r"(?:public|private|protected|static|\s)*\s*function\s+(\w+)\s*\(",
    "python": r"(?:def|async\s+def)\s+(\w+)\s*\(",
    "javascript": r"(?:export\s+)?(?:async\s+)?function\s+(\w+)\s*\(|(?:const|let|var)\s+(\w+)\s*=\s*(?:async\s+)?\(",
}


# --- Function/Method Extraction ---

def extract_methods_generic(content: str, pattern: str) -> list[dict]:
    """Extract functions/methods using a regex pattern with brace-counting."""
    methods = []
    lines = content.split("\n")
    i = 0

    compiled = re.compile(pattern)

    while i < len(lines):
        line = lines[i].strip()
        m = compiled.search(line)

        if m:
            name = m.group(1) if m.lastindex and m.group(1) else m.group(0)[:50]

            # Grab preceding docblock/comment
            docblock = ""
            j = i - 1
            while j >= 0 and lines[j].strip() == "":
                j -= 1
            if j >= 0:
                doc_line = lines[j].strip()
                if doc_line.endswith("*/") or doc_line.startswith("#") or doc_line.startswith("//"):
                    doc_end = j
                    if doc_line.endswith("*/"):
                        while j >= 0 and "/**" not in lines[j] and "/*" not in lines[j]:
                            j -= 1
                    elif doc_line.startswith("#") or doc_line.startswith("//"):
                        while j > 0 and (lines[j - 1].strip().startswith("#") or lines[j - 1].strip().startswith("//")):
                            j -= 1
                    docblock = "\n".join(lines[j : doc_end + 1])

            # Grab body (brace counting)
            brace_count = 0
            method_lines = []
            k = i
            started = False
            while k < len(lines):
                method_lines.append(lines[k])
                brace_count += lines[k].count("{") - lines[k].count("}")
                if "{" in lines[k]:
                    started = True
                if started and brace_count <= 0:
                    break
                k += 1

            methods.append({
                "name": name.strip(),
                "docblock": docblock.strip(),
                "code": "\n".join(method_lines),
                "line_start": i + 1,
                "line_end": k + 1,
            })
            i = k + 1
        else:
            i += 1

    return methods


def extract_python_functions(content: str, pattern: str) -> list[dict]:
    """Extract Python functions using indentation-based scoping."""
    methods = []
    lines = content.split("\n")
    i = 0
    compiled = re.compile(pattern)

    while i < len(lines):
        line = lines[i]
        stripped = line.strip()
        m = compiled.search(stripped)

        if m:
            name = m.group(1) if m.lastindex else stripped[:50]
            indent = len(line) - len(line.lstrip())

            # Grab preceding docblock/decorator
            docblock = ""
            j = i - 1
            while j >= 0 and lines[j].strip() == "":
                j -= 1
            if j >= 0:
                doc_lines = []
                while j >= 0 and (lines[j].strip().startswith("@") or lines[j].strip().startswith("#")):
                    doc_lines.insert(0, lines[j])
                    j -= 1
                docblock = "\n".join(doc_lines)

            # Grab body (indentation-based)
            func_lines = [lines[i]]
            k = i + 1
            while k < len(lines):
                if lines[k].strip() == "":
                    func_lines.append(lines[k])
                    k += 1
                    continue
                curr_indent = len(lines[k]) - len(lines[k].lstrip())
                if curr_indent <= indent:
                    break
                func_lines.append(lines[k])
                k += 1

            # Strip trailing blank lines
            while func_lines and func_lines[-1].strip() == "":
                func_lines.pop()

            methods.append({
                "name": name.strip(),
                "docblock": docblock.strip(),
                "code": "\n".join(func_lines),
                "line_start": i + 1,
                "line_end": k,
            })
            i = k
        else:
            i += 1

    return methods


def extract_css_components(content: str, filepath: Path) -> list[dict]:
    """Extract CSS rule blocks and HTML component blocks."""
    components = []
    ext = filepath.suffix.lower()

    if ext in ('.html', '.htm', '.vue', '.svelte'):
        # Extract HTML component blocks
        blocks = re.split(r'(?=<(?:div|section|article|nav|header|footer|main|aside|form)\s)', content)
        for block in blocks:
            block = block.strip()
            if len(block) < 20:
                continue
            # Get first tag's class
            m = re.search(r'class="([^"]+)"', block[:200])
            name = m.group(1).split()[0] if m else "component"
            lines = block.split("\n")
            if len(lines) >= 3:
                components.append({
                    "name": name,
                    "docblock": "",
                    "code": "\n".join(lines[:80]),
                    "line_start": 0,
                    "line_end": min(len(lines), 80),
                })
    else:
        # Extract CSS rule blocks
        blocks = re.findall(
            r'((?:@media[^{]+|[.#\w\[\*][^{]*)\{[^}]*(?:\{[^}]*\}[^}]*)*\})',
            content, re.DOTALL
        )
        for block in blocks:
            block = block.strip()
            if len(block.split("\n")) < 3:
                continue
            selector = block.split("{")[0].strip()
            components.append({
                "name": selector[:60],
                "docblock": "",
                "code": block,
                "line_start": 0,
                "line_end": 0,
            })

    return components


# --- Main Extraction Logic ---

def should_skip(filepath: Path, skip_dirs: list[str]) -> bool:
    parts = str(filepath)
    return any(f"/{skip}/" in parts or parts.endswith(f"/{skip}") for skip in skip_dirs)


def collect_files(project_path: str, extensions: list[str], skip_dirs: list[str]) -> list[Path]:
    """Find all matching files in project."""
    project = Path(project_path)
    if not project.exists():
        print(f"  SKIP: {project_path} not found")
        return []

    files = []
    for ext in extensions:
        # Normalize extension
        ext = ext if ext.startswith(".") else f".{ext}"
        for f in project.rglob(f"*{ext}"):
            if f.is_file() and not should_skip(f, skip_dirs):
                files.append(f)

    # Deduplicate
    seen = set()
    unique = []
    for f in sorted(files):
        resolved = f.resolve()
        if resolved not in seen:
            seen.add(resolved)
            unique.append(f)

    return unique


def main():
    parser = argparse.ArgumentParser(description="Multi-language code extractor")
    parser.add_argument("--config", help="Config JSON file path")
    parser.add_argument("--project-path", help="Project directory to extract from")
    parser.add_argument("--output", help="Output JSONL file path")
    parser.add_argument("--language", default="php", help="Language (php, python, javascript, css)")
    parser.add_argument("--extensions", help="Comma-separated file extensions")
    parser.add_argument("--skip-dirs", help="Comma-separated directories to skip")
    parser.add_argument("--function-pattern", help="Regex for function extraction")
    parser.add_argument("--metadata-extractor", help="Metadata extractor type")
    args = parser.parse_args()

    # Load config
    if args.config:
        config = load_config(args.config)
    else:
        config = {
            "project_path": args.project_path or ".",
            "output_file": args.output or "code_samples.jsonl",
            "language": args.language,
            "extensions": args.extensions.split(",") if args.extensions else [f".{args.language[:3]}"],
            "skip_dirs": args.skip_dirs.split(",") if args.skip_dirs else [".git", "vendor", "node_modules"],
            "function_pattern": args.function_pattern or "",
            "metadata_extractor": args.metadata_extractor or args.language,
        }

    project_path = config["project_path"]
    output_file = config["output_file"]
    language = config["language"]
    extensions = config["extensions"]
    skip_dirs = config["skip_dirs"]
    function_pattern = config.get("function_pattern", "")
    metadata_extractor = config.get("metadata_extractor", language)

    print(f"Extracting {language} code from: {project_path}")
    print(f"Extensions: {extensions}")

    # Ensure output directory exists
    output_path = Path(output_file)
    output_path.parent.mkdir(parents=True, exist_ok=True)

    # Get metadata extractor
    meta_func = METADATA_EXTRACTORS.get(metadata_extractor, extract_php_metadata)

    # Collect files
    files = collect_files(project_path, extensions, skip_dirs)
    print(f"Found {len(files)} files")

    samples = []

    for filepath in files:
        content = filepath.read_text(errors="replace")

        # Skip tiny files
        if len(content.strip().split("\n")) < 10:
            continue

        meta = meta_func(filepath, content)
        meta["project"] = Path(project_path).name

        # Full file sample
        samples.append({
            "type": "full_file",
            "metadata": meta,
            "content": content,
            "line_count": len(content.split("\n")),
        })

        # Extract methods/functions/components
        if metadata_extractor == "css":
            components = extract_css_components(content, filepath)
            for comp in components:
                if len(comp["code"].split("\n")) < 3:
                    continue
                samples.append({
                    "type": "component",
                    "metadata": meta,
                    "component": comp,
                })
        elif metadata_extractor == "python":
            pattern = function_pattern or DEFAULT_FUNCTION_PATTERNS.get("python", "")
            if pattern:
                methods = extract_python_functions(content, pattern)
            else:
                methods = []
            for method in methods:
                if len(method["code"].split("\n")) < 3:
                    continue
                samples.append({
                    "type": "method",
                    "metadata": meta,
                    "method": method,
                })
        else:
            pattern = function_pattern or DEFAULT_FUNCTION_PATTERNS.get(metadata_extractor, "")
            if pattern:
                methods = extract_methods_generic(content, pattern)
            else:
                methods = []
            for method in methods:
                if len(method["code"].split("\n")) < 3:
                    continue
                samples.append({
                    "type": "method",
                    "metadata": meta,
                    "method": method,
                })

    # Write output
    with open(output_file, "w") as f:
        for sample in samples:
            f.write(json.dumps(sample) + "\n")

    full_count = sum(1 for s in samples if s["type"] == "full_file")
    method_count = sum(1 for s in samples if s["type"] == "method")
    comp_count = sum(1 for s in samples if s["type"] == "component")

    print(f"\nExtracted {len(samples)} total samples from {len(files)} files")
    print(f"  Full files:  {full_count}")
    print(f"  Methods:     {method_count}")
    print(f"  Components:  {comp_count}")
    print(f"Output: {output_file}")


if __name__ == "__main__":
    main()
