#!/usr/bin/env python3
"""BMS24 Stage 1881 — inspect KBA Connect lifecycle without executing PHP.

Read-only and intentionally prints metadata (file names, line numbers,
function names, finding labels) instead of code, URLs, or secret values.
An absence of flags does NOT prove an installation has no side effects.
"""
from __future__ import annotations

import pathlib
import re
import sys

ROOT = pathlib.Path(
    "/srv/bms24-staging/import/production-snapshot/source/local/bitrixsync"
)

ENTRYPOINTS = (
    "version.php",
    "db/install.php",
    "db/upgrade.php",
    "db/tasks.php",
    "db/events.php",
    "settings.php",
    "lib.php",
    "classes/services/production_activation_service.php",
    "classes/services/production_launch_service.php",
    "classes/services/sync_manager.php",
)

PATTERNS = {
    "network-client": r"curl_exec\s*\(|curl_init\s*\(|new\s+\\?curl\b|file_get_contents\s*\(|->(?:post|get|request|execute)\s*\(",
    "outbound-service": r"bitrix24|webhook|http_client|api_client|client_secret|access_token",
    "queue-or-cron": r"queue_adhoc_task|scheduled_task|cron|adhoc_task",
    "event-registration": r"observer|callback|\\\\core\\\\event|\btrigger\s*\(",
    "setting-change": r"set_config\s*\(|config_plugins|insert_record\s*\(|update_record\s*\(|delete_records?\s*\(",
    "file-process": r"include(?:_once)?\s*\(|require(?:_once)?\s*\(|shell_exec|proc_open|exec\s*\(",
    "activation-path": r"production_activation|production_launch|enable_inbound|go_live",
}

def inspect(path: pathlib.Path) -> None:
    name = path.relative_to(ROOT).as_posix()
    if not path.exists():
        print(f"FILE {name}: ABSENT")
        return
    if not path.is_file() or path.is_symlink():
        raise SystemExit(f"BLOCKED: unsafe or unexpected file type: {name}")
    if path.stat().st_size > 1024 * 1024:
        raise SystemExit(f"BLOCKED: unexpectedly large file: {name}")
    text = path.read_text(encoding="utf-8", errors="replace")
    lines = text.splitlines()
    print(f"FILE {name}: PRESENT lines={len(lines)}")
    names = re.findall(
        r"\bfunction\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(", text,
        flags=re.I,
    )
    print("  functions:", ", ".join(names[:25]) if names else "(not declared)")
    for label, pattern in PATTERNS.items():
        matched = [
            str(index)
            for index, line in enumerate(lines, 1)
            if re.search(pattern, line, flags=re.I)
        ]
        if matched:
            print(f"  {label}: {len(matched)} line(s) at {', '.join(matched[:16])}")

def main() -> None:
    if not ROOT.is_dir():
        raise SystemExit("BLOCKED: expected source directory is missing")
    print("STAGE1881_LIFECYCLE_AUDIT")
    for relative in ENTRYPOINTS:
        inspect(ROOT / relative)
    print("REVIEW_REQUIRED: flags are heuristic; manually review install/upgrade call chains")
    print("NO_CHANGES_PERFORMED")

if __name__ == "__main__":
    main()
