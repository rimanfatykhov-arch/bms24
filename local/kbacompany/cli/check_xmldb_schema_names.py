#!/usr/bin/env python3
"""Fail the release for Moodle XMLDB table names that exceed its 28-char limit."""
from __future__ import annotations
import re
from pathlib import Path
from xml.etree import ElementTree as ET

ROOT = Path(__file__).resolve().parents[1]
LIMIT = 28


def validate(xml_text: str, upgrade_text: str) -> list[str]:
    root = ET.fromstring(xml_text)
    tables = [t.attrib.get('NAME', '') for t in root.findall('.//TABLES/TABLE')]
    migrations = re.findall(r'new\s+xmldb_table\s*\(\s*[\'\"]([^\'\"]+)[\'\"]\s*\)', upgrade_text)
    errors = []
    for name in tables:
        if len(name) > LIMIT:
            errors.append(f'install.xml: table {name!r} has {len(name)} chars (max {LIMIT})')
    for name in migrations:
        if len(name) > LIMIT:
            errors.append(f'upgrade.php: table {name!r} has {len(name)} chars (max {LIMIT})')
    if len(tables) != len(set(tables)):
        errors.append('install.xml: duplicate table names')
    return errors


def main() -> None:
    import sys
    xml = (ROOT / 'db/install.xml').read_text(encoding='utf-8')
    upgrade = (ROOT / 'db/upgrade.php').read_text(encoding='utf-8')
    errors = validate(xml, upgrade)
    if errors:
        for error in errors:
            print('FAIL:', error, file=sys.stderr)
        raise SystemExit(2)
    print(f'PASS: XMLDB table names comply with <= {LIMIT} characters')

if __name__ == '__main__':
    main()
