#!/usr/bin/env python3
"""
Extract database schema from MySQL dumps, SQLite databases, and PHP code patterns.
Produces a JSON schema file that can be used for schema-aware training data generation.

Usage:
  python3 extract_schema.py --mysql-dump schema.sql --output schema.json
  python3 extract_schema.py --php-project /path/to/project --output schema.json
  python3 extract_schema.py --mysql-dump schema.sql --php-project /path/to/project --output schema.json
"""

import argparse
import glob
import json
import re
import sys
from pathlib import Path


def parse_mysql_dump(dump_path: str) -> dict:
    """Parse a MySQL dump file and extract table/column definitions."""
    tables = {}
    current_table = None
    current_columns = []

    with open(dump_path) as f:
        for line in f:
            line = line.strip()

            # Match CREATE TABLE
            match = re.match(r'CREATE TABLE\s+`?(\w+)`?\s*\(', line, re.IGNORECASE)
            if match:
                current_table = match.group(1)
                current_columns = []
                continue

            if current_table:
                # Match column definition
                col_match = re.match(r'`?(\w+)`?\s+([\w()]+(?:\([^)]*\))?)', line)
                if col_match and not line.upper().startswith(('PRIMARY', 'KEY', 'INDEX', 'UNIQUE', 'CONSTRAINT', 'FOREIGN', ')')):
                    col_name = col_match.group(1)
                    col_type = col_match.group(2).upper()
                    nullable = 'NOT NULL' not in line.upper()
                    default = None
                    default_match = re.search(r"DEFAULT\s+'?([^',)]+)'?", line, re.IGNORECASE)
                    if default_match:
                        default = default_match.group(1)

                    current_columns.append({
                        "name": col_name,
                        "type": col_type,
                        "nullable": nullable,
                        "default": default,
                    })

                # End of table
                if line.startswith(')'):
                    if current_table and current_columns:
                        tables[current_table] = {
                            "columns": current_columns,
                            "source": "mysql_dump",
                        }
                    current_table = None

    return tables


def extract_from_php(project_path: str) -> dict:
    """Extract table/column info from PHP code using RedBeanPHP patterns."""
    tables = {}

    for php_file in glob.glob(f"{project_path}/**/*.php", recursive=True):
        if "/vendor/" in php_file:
            continue
        try:
            content = open(php_file).read()
        except (IOError, UnicodeDecodeError):
            continue

        # Find R::dispense('tablename')
        for match in re.finditer(r"(?:R|Bean)::dispense\s*\(\s*['\"](\w+)['\"]", content):
            table = match.group(1).lower()
            if table not in tables:
                tables[table] = {"columns": [], "source": "php_code", "_fields": set()}

        # Find field access: $bean->fieldname
        for match in re.finditer(r"\$\w+->([\w]+)\s*[=;]", content):
            field = match.group(1)
            skip = {"id", "box", "bean", "getMeta", "setMeta", "unbox", "export",
                    "ownList", "sharedList", "fetchAs", "alias", "with", "noLoad"}
            if field not in skip and not field.startswith("__"):
                # Can't associate with a specific table easily, add to all recent
                pass

        # Find columns in R::find queries
        for match in re.finditer(r"(?:R|Bean)::(?:find(?:One|All)?|getAll)\s*\(\s*['\"](\w+)['\"],\s*['\"]([^'\"]+)['\"]", content):
            table = match.group(1).lower()
            sql = match.group(2)
            if table not in tables:
                tables[table] = {"columns": [], "source": "php_code", "_fields": set()}

            for col in re.findall(r"(\w+)\s*(?:=|LIKE|>|<|IS|IN|BETWEEN|ORDER BY|GROUP BY)", sql, re.IGNORECASE):
                col = col.lower()
                if col not in ("and", "or", "not", "null", "by", "desc", "asc", "limit", "offset", "where"):
                    tables[table]["_fields"].add(col)

        # Find columns from $bean->field patterns near dispense
        for match in re.finditer(
            r"R::dispense\s*\(\s*['\"](\w+)['\"]\s*\).*?(?=R::store|R::dispense|\Z)",
            content, re.DOTALL
        ):
            table = match.group(1).lower()
            block = match.group(0)
            if table not in tables:
                tables[table] = {"columns": [], "source": "php_code", "_fields": set()}

            for field_match in re.finditer(r"\$\w+->([\w]+)\s*=", block):
                field = field_match.group(1)
                if field not in ("id",) and not field.startswith("__"):
                    tables[table]["_fields"].add(field)

    # Convert _fields sets to column lists
    for table, info in tables.items():
        fields = info.pop("_fields", set())
        existing_names = {c["name"] for c in info["columns"]}
        for field in sorted(fields):
            if field not in existing_names:
                info["columns"].append({
                    "name": field,
                    "type": "unknown",
                    "nullable": True,
                    "default": None,
                })

    return tables


def merge_schemas(*schemas):
    """Merge multiple schema dicts, combining columns."""
    merged = {}
    for schema in schemas:
        for table, info in schema.items():
            if table not in merged:
                merged[table] = {"columns": [], "source": info.get("source", "unknown")}

            existing_names = {c["name"] for c in merged[table]["columns"]}
            for col in info["columns"]:
                if col["name"] not in existing_names:
                    merged[table]["columns"].append(col)

    return merged


def schema_to_text(schema: dict, project_name: str = "") -> str:
    """Convert schema dict to a readable text format for training prompts."""
    lines = []
    if project_name:
        lines.append(f"# {project_name} Database Schema\n")

    for table in sorted(schema.keys()):
        info = schema[table]
        cols = info["columns"]
        lines.append(f"## Table: {table}")
        if cols:
            for col in cols:
                type_str = col.get("type", "unknown")
                nullable = " (nullable)" if col.get("nullable") else " NOT NULL"
                default = f" DEFAULT {col['default']}" if col.get("default") else ""
                lines.append(f"  - {col['name']}: {type_str}{nullable}{default}")
        else:
            lines.append("  (columns discovered from code patterns)")
        lines.append("")

    return "\n".join(lines)


def main():
    parser = argparse.ArgumentParser(description="Extract database schema")
    parser.add_argument("--mysql-dump", help="MySQL dump file path")
    parser.add_argument("--php-project", help="PHP project path to scan")
    parser.add_argument("--output", required=True, help="Output JSON schema file")
    parser.add_argument("--text", help="Also output readable text format")
    parser.add_argument("--project-name", default="", help="Project name for text output")
    args = parser.parse_args()

    schemas = []

    if args.mysql_dump:
        print(f"Parsing MySQL dump: {args.mysql_dump}")
        mysql_schema = parse_mysql_dump(args.mysql_dump)
        print(f"  Found {len(mysql_schema)} tables")
        schemas.append(mysql_schema)

    if args.php_project:
        print(f"Scanning PHP project: {args.php_project}")
        php_schema = extract_from_php(args.php_project)
        print(f"  Found {len(php_schema)} tables")
        schemas.append(php_schema)

    if not schemas:
        print("ERROR: Provide --mysql-dump and/or --php-project")
        sys.exit(1)

    merged = merge_schemas(*schemas)
    total_cols = sum(len(info["columns"]) for info in merged.values())
    print(f"Merged: {len(merged)} tables, {total_cols} columns")

    with open(args.output, "w") as f:
        json.dump(merged, f, indent=2)
    print(f"Schema saved to: {args.output}")

    if args.text:
        text = schema_to_text(merged, args.project_name)
        with open(args.text, "w") as f:
            f.write(text)
        print(f"Text schema saved to: {args.text}")


if __name__ == "__main__":
    main()
