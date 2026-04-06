#!/usr/bin/env python3
"""
Training Data Pool — SQLite registry for accumulating training examples across runs.

Stores all generated examples with metadata, deduplicates by content hash,
and exports filtered datasets for training.

Usage:
  # Import existing JSONL into the pool
  python3 pool.py import data/dataset.jsonl --source myctobot --category code

  # Export pool to JSONL for training
  python3 pool.py export data/training.jsonl
  python3 pool.py export data/training.jsonl --source myctobot
  python3 pool.py export data/training.jsonl --category tool_use

  # Show pool stats
  python3 pool.py stats

  # Prune duplicates or bad entries
  python3 pool.py prune
"""

import argparse
import hashlib
import json
import os
import sqlite3
import sys
from pathlib import Path


DEFAULT_DB = os.path.join(os.path.dirname(__file__), "..", "data", "training_pool.db")


class TrainingPool:
    def __init__(self, db_path: str = DEFAULT_DB):
        os.makedirs(os.path.dirname(db_path), exist_ok=True)
        self.db = sqlite3.connect(db_path)
        self.db.row_factory = sqlite3.Row
        self.db.execute("PRAGMA journal_mode=WAL")
        self.db.execute("PRAGMA busy_timeout=5000")
        self._create_tables()

    def _create_tables(self):
        self.db.executescript("""
            CREATE TABLE IF NOT EXISTS examples (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                content_hash TEXT UNIQUE NOT NULL,
                messages TEXT NOT NULL,
                source TEXT,
                category TEXT,
                distill_model TEXT,
                num_messages INTEGER,
                has_tool_calls BOOLEAN DEFAULT 0,
                created_at TEXT DEFAULT (datetime('now'))
            );

            CREATE INDEX IF NOT EXISTS idx_source ON examples(source);
            CREATE INDEX IF NOT EXISTS idx_category ON examples(category);
            CREATE INDEX IF NOT EXISTS idx_has_tool_calls ON examples(has_tool_calls);
        """)

    def _hash_content(self, messages: list) -> str:
        """Hash the user message content for deduplication."""
        user_msgs = [m["content"][:200] for m in messages if m["role"] == "user"]
        return hashlib.sha256("||".join(user_msgs).encode()).hexdigest()[:32]

    def add(self, messages: list, source: str = "", category: str = "",
            distill_model: str = "") -> bool:
        """Add an example to the pool. Returns True if new, False if duplicate."""
        content_hash = self._hash_content(messages)
        has_tool_calls = any("<tool_call>" in m.get("content", "") for m in messages)

        try:
            self.db.execute(
                """INSERT INTO examples (content_hash, messages, source, category,
                   distill_model, num_messages, has_tool_calls)
                   VALUES (?, ?, ?, ?, ?, ?, ?)""",
                (content_hash, json.dumps(messages), source, category,
                 distill_model, len(messages), has_tool_calls)
            )
            self.db.commit()
            return True
        except sqlite3.IntegrityError:
            return False  # Duplicate

    def add_jsonl(self, path: str, source: str = "", category: str = "",
                  distill_model: str = "") -> tuple[int, int]:
        """Import a JSONL file into the pool. Returns (added, skipped)."""
        added = 0
        skipped = 0
        with open(path) as f:
            for line in f:
                line = line.strip()
                if not line:
                    continue
                try:
                    data = json.loads(line)
                    messages = data.get("messages", [])
                    if not messages:
                        skipped += 1
                        continue

                    # Auto-detect category
                    cat = category
                    if not cat:
                        if any("<tool_call>" in m.get("content", "") for m in messages):
                            cat = "tool_use"
                        else:
                            cat = "code"

                    if self.add(messages, source=source, category=cat,
                                distill_model=distill_model):
                        added += 1
                    else:
                        skipped += 1
                except (json.JSONDecodeError, KeyError):
                    skipped += 1
        return added, skipped

    def export(self, path: str, source: str = None, category: str = None,
               has_tool_calls: bool = None, limit: int = None) -> int:
        """Export filtered examples to JSONL."""
        query = "SELECT messages FROM examples WHERE 1=1"
        params = []

        if source:
            query += " AND source = ?"
            params.append(source)
        if category:
            query += " AND category = ?"
            params.append(category)
        if has_tool_calls is not None:
            query += " AND has_tool_calls = ?"
            params.append(has_tool_calls)

        query += " ORDER BY created_at"

        if limit:
            query += " LIMIT ?"
            params.append(limit)

        cursor = self.db.execute(query, params)
        count = 0
        os.makedirs(os.path.dirname(path) or ".", exist_ok=True)
        with open(path, "w") as f:
            for row in cursor:
                messages = json.loads(row["messages"])
                f.write(json.dumps({"messages": messages}) + "\n")
                count += 1
        return count

    def stats(self) -> dict:
        """Get pool statistics."""
        total = self.db.execute("SELECT COUNT(*) FROM examples").fetchone()[0]
        by_source = dict(self.db.execute(
            "SELECT COALESCE(source, 'unknown'), COUNT(*) FROM examples GROUP BY source"
        ).fetchall())
        by_category = dict(self.db.execute(
            "SELECT COALESCE(category, 'unknown'), COUNT(*) FROM examples GROUP BY category"
        ).fetchall())
        tool_count = self.db.execute(
            "SELECT COUNT(*) FROM examples WHERE has_tool_calls = 1"
        ).fetchone()[0]

        return {
            "total": total,
            "by_source": by_source,
            "by_category": by_category,
            "tool_use_examples": tool_count,
            "code_examples": total - tool_count,
        }

    def count(self, source: str = None, category: str = None) -> int:
        """Count examples matching filters."""
        query = "SELECT COUNT(*) FROM examples WHERE 1=1"
        params = []
        if source:
            query += " AND source = ?"
            params.append(source)
        if category:
            query += " AND category = ?"
            params.append(category)
        return self.db.execute(query, params).fetchone()[0]

    def prune(self) -> int:
        """Remove examples with empty or invalid messages."""
        cursor = self.db.execute("SELECT id, messages FROM examples")
        bad_ids = []
        for row in cursor:
            try:
                msgs = json.loads(row["messages"])
                if not msgs or len(msgs) < 2:
                    bad_ids.append(row["id"])
            except json.JSONDecodeError:
                bad_ids.append(row["id"])

        if bad_ids:
            self.db.execute(
                f"DELETE FROM examples WHERE id IN ({','.join('?' * len(bad_ids))})",
                bad_ids
            )
            self.db.commit()
        return len(bad_ids)


def main():
    parser = argparse.ArgumentParser(description="Training Data Pool")
    parser.add_argument("--db", default=DEFAULT_DB, help="Pool database path")
    sub = parser.add_subparsers(dest="command")

    # Import
    imp = sub.add_parser("import", help="Import JSONL into pool")
    imp.add_argument("file", help="JSONL file to import")
    imp.add_argument("--source", default="", help="Source tag (e.g., myctobot)")
    imp.add_argument("--category", default="", help="Category (code, tool_use, etc.)")
    imp.add_argument("--model", default="", help="Model used for generation")

    # Export
    exp = sub.add_parser("export", help="Export pool to JSONL")
    exp.add_argument("file", help="Output JSONL file")
    exp.add_argument("--source", help="Filter by source")
    exp.add_argument("--category", help="Filter by category")
    exp.add_argument("--tools-only", action="store_true", help="Only tool-use examples")
    exp.add_argument("--code-only", action="store_true", help="Only code examples")
    exp.add_argument("--limit", type=int, help="Max examples")

    # Stats
    sub.add_parser("stats", help="Show pool statistics")

    # Prune
    sub.add_parser("prune", help="Remove invalid entries")

    args = parser.parse_args()

    if not args.command:
        parser.print_help()
        return

    pool = TrainingPool(args.db)

    if args.command == "import":
        added, skipped = pool.add_jsonl(args.file, source=args.source,
                                         category=args.category, distill_model=args.model)
        print(f"Imported: {added} new, {skipped} skipped (duplicates/invalid)")

    elif args.command == "export":
        has_tools = None
        if args.tools_only:
            has_tools = True
        elif args.code_only:
            has_tools = False
        count = pool.export(args.file, source=args.source, category=args.category,
                           has_tool_calls=has_tools, limit=args.limit)
        print(f"Exported {count} examples to {args.file}")

    elif args.command == "stats":
        s = pool.stats()
        print(f"Total examples: {s['total']}")
        print(f"  Code:         {s['code_examples']}")
        print(f"  Tool-use:     {s['tool_use_examples']}")
        print(f"\nBy source:")
        for src, count in sorted(s["by_source"].items()):
            print(f"  {src}: {count}")
        print(f"\nBy category:")
        for cat, count in sorted(s["by_category"].items()):
            print(f"  {cat}: {count}")

    elif args.command == "prune":
        removed = pool.prune()
        print(f"Pruned {removed} invalid entries")


if __name__ == "__main__":
    main()
