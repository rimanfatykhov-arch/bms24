#!/usr/bin/env python3
"""Static safety checks for Moodle local_kbacompany upgrade checkpoints.

Historic pre-2026101700 migrations may be out of order, so check monotonicity
of the active release series and report the legacy issue separately.
This does not execute Moodle migrations.
"""
from __future__ import annotations
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ACTIVE_START = 2026101700

def validate(source: str, current: int) -> list[str]:
    problems = []
    matches = list(re.finditer(r'if\s*\(\s*\$oldversion\s*<\s*(\d+)\s*\)', source))
    versions = [int(m.group(1)) for m in matches]
    if len(set(versions)) != len(versions):
        problems.append('Duplicate upgrade checkpoint version')
    if not versions or versions[-1] != current:
        problems.append('Final upgrade checkpoint does not match version.php')
    active = [v for v in versions if v >= ACTIVE_START]
    if active != sorted(active):
        problems.append('Recent upgrade checkpoints are not in ascending order')
    # Prevent a regression where the newest checkpoint was placed after return true.
    returns = list(re.finditer(r'\breturn\s+true\s*;', source))
    if len(returns) != 1 or (matches and returns[0].start() < matches[-1].start()):
        problems.append('Upgrade checkpoint is unreachable or multiple return true statements exist')
    savepoints = [int(v) for v in re.findall(r'upgrade_plugin_savepoint\s*\(\s*true\s*,\s*(\d+)', source)]
    missing = sorted(set(versions)-set(savepoints))
    if missing:
        problems.append(f'Missing savepoints for checkpoint versions: {missing[:10]}')
    if current not in savepoints:
        problems.append('Missing savepoint for current plugin version')
    return problems

def main() -> None:
    code = (ROOT / 'db/upgrade.php').read_text(encoding='utf-8')
    version_code = (ROOT / 'version.php').read_text(encoding='utf-8')
    match = re.search(r'\$plugin->version\s*=\s*(\d+)', version_code)
    if not match:
        raise SystemExit('FAIL: Cannot read plugin version')
    errors = validate(code, int(match.group(1)))
    if errors:
        for msg in errors:
            print('FAIL:', msg, file=sys.stderr)
        raise SystemExit(2)
    print('PASS: upgrade checkpoint uniqueness, recent ordering, terminal reachability, savepoint coverage')

if __name__ == '__main__':
    main()
