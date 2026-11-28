#!/usr/bin/env python3
"""BDO-1 CLI entry point."""
import argparse
from datetime import timezone
import json
import os
from pathlib import Path
import re
import sys

from github import GitHub, collect, now
from policy import classify, obj, timestamp

OUTPUTS = ("snapshot.json", "development-report.md", "blockers.json", "ready-tasks.json", "owner-decisions.md")


def read_json(path):
    def unique(pairs):
        result = dict(pairs)
        if len(result) != len(pairs):
            raise ValueError("duplicate key")
        return result

    with path.open("rb") as stream:
        data = stream.read(8_000_001)
    if len(data) > 8_000_000:
        raise ValueError("input size")
    payload = json.loads(data, object_pairs_hook=unique)
    if not isinstance(payload, dict) or type(payload.get("schema_version")) is not int or payload["schema_version"] != 1:
        raise ValueError("schema version")
    return payload


def render_markdown(snapshot, rows, owner=False):
    title = "Owner decisions" if owner else "Development report"
    lines = [f"# {title}", "", f"Collected UTC {snapshot['collected_at'] or 'unknown'}",
             f"Main `{snapshot['main'] or 'unknown'}`", f"Mode {snapshot['mode']}",
             f"Complete {str(snapshot['complete']).lower()}, fresh {str(snapshot['fresh']).lower()}, "
             f"consistent {str(snapshot['consistent']).lower()}", ""]
    if not owner:
        lines.extend(f"- Source error `{e['endpoint']}` ({e['reason']})" for e in snapshot["errors"])
    if not rows:
        lines.extend(["", "No matching findings."])
    for row in rows:
        n = row["number"]
        lines += [
            "", f"## [{row['kind'].upper()} #{n}]({row['source']}) · {row['status']}", "",
            f"- Executor {row['executor'] or 'unknown'}, source SHA `{row['head'] or 'unknown'}`",
            f"- Reason `{row['reason']}`",
            f"- CI {row['ci']}, independent review {row['independent_review']}, release {row['release']}, docs {row['docs']}",
            f"- {row['recommended_action']}",
        ]
    return "\n".join(lines).rstrip() + "\n"


def report(snapshot, directory):
    findings = snapshot["findings"]
    blocked = [r for r in findings if r["status"] not in {"READY", "COMPLETE"}]
    ready = [r for r in findings if r["status"] == "READY"]
    decisions = [r for r in blocked if r["reason"] in {"assignment_unknown", "recorded_hold"}]
    for row in findings:
        if row["release_reason"] == "owner_decision_missing":
            decision = dict(row, reason="owner_decision_missing",
                            recommended_action="Record the owner decision for this package.")
            decisions.append(decision)
    meta = {k: snapshot[k] for k in ("schema_version", "collected_at", "main", "complete", "fresh", "consistent")}
    def json_text(data):
        return json.dumps(data, ensure_ascii=False, indent=2) + "\n"

    contents = [
        json_text(snapshot), render_markdown(snapshot, findings),
        json_text(dict(meta, findings=blocked)), json_text(dict(meta, findings=ready)),
        render_markdown(snapshot, decisions, owner=True),
    ]
    # All output paths are direct children of a new directory; never overwrite a prior report or follow a file symlink.
    directory.mkdir()
    for name, value in zip(OUTPUTS, contents):
        with (directory / name).open("x", encoding="utf-8", newline="\n") as file:
            file.write(value)


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument("--fixture", type=Path)
    mode.add_argument("--github", action="store_true")
    parser.add_argument("--evidence", type=Path, help="Locally verified version-1 evidence; never read from GitHub text.")
    parser.add_argument("--as-of", help="Fixed UTC evaluation time for offline replay.")
    parser.add_argument("--out", required=True, type=Path, help="New local output directory.")
    args = parser.parse_args(argv)
    if args.as_of and (args.github or not timestamp(args.as_of)):
        parser.error("--as-of requires offline mode and a UTC timestamp")
    current = timestamp(args.as_of or now()).astimezone(timezone.utc)
    try:
        inputs = read_json(args.evidence) if args.evidence else {}
        if args.github:
            token = os.environ.get("BDO_GITHUB_READ_TOKEN", "")
            if not re.fullmatch(r"github_pat_[A-Za-z0-9_]{20,250}", token):
                print("GitHub collection NOT RUN. Supply a repository-scoped fine-grained read-only token.",
                      file=sys.stderr)
                return 2
            snapshot = collect(GitHub(token), obj(inputs.get("tasks")))
            current = timestamp(now())
        else:
            bundle = read_json(args.fixture)
            snapshot = dict(obj(bundle.get("snapshot")), mode="offline")
            if not args.evidence:
                inputs = obj(bundle.get("evidence"))
        result = classify(snapshot, inputs, current)
        report(result, args.out)
    except (OSError, ValueError, RecursionError):
        print("Observer error. Check the input contract and choose a new writable output directory.",
              file=sys.stderr)
        return 2
    return 0 if all(result[key] for key in ("complete", "fresh", "consistent")) else 1


if __name__ == "__main__":
    raise SystemExit(main())
