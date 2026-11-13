#!/usr/bin/env python3
"""Pinned provider source gate, no Moodle boot, network or database."""
import hashlib
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def check_source(root=ROOT):
    errors, allowed = [], set()
    manifest = json.loads((root / 'docs/reconcile/corporate-api126-source.json').read_text())
    if (manifest['selected_version'] != 2026102610 or manifest['api_release'] != '1.26'
            or manifest['archive_sha256'] != '85ec38d0292543e9ad87572a091529ae765c7901015044914951ccac6e22e141'):
        errors.append('Corporate source identity mismatch')
    entries = manifest['entries']
    versiontext = (root / 'local/kbacorporate/version.php').read_text()
    version = re.search(r'\$plugin->version\s*=\s*(\d+)', versiontext)
    if manifest.get('candidate_version') != 2026111100 or not version or int(version.group(1)) != 2026111100:
        errors.append('Corporate local candidate version mismatch')
    paths = [row['path'] for row in entries]
    if len(paths) != 817 or len(set(paths)) != len(paths):
        errors.append('Corporate inventory incomplete or duplicated')
    expected_changes = [row for row in entries if row['status'] != 'unchanged']
    if manifest['reviewed_changes'] != expected_changes:
        errors.append('Corporate reviewed changes differ from inventory')
    for row in entries:
        path = row['path']
        if not path.startswith('local/kbacorporate/') or '..' in Path(path).parts:
            errors.append('unsafe Corporate inventory path')
            continue
        file = root / path
        if file.is_symlink() or not file.is_file():
            errors.append('missing or unsafe Corporate source: ' + path)
            continue
        actual = hashlib.sha256(file.read_bytes().replace(b'\r\n', b'\n')).hexdigest()
        if actual != row['source_sha256']:
            errors.append('Corporate source hash mismatch: ' + path)
        elif row['status'] != 'unchanged':
            allowed.add(path)
    actual_paths = {p.relative_to(root).as_posix() for p in (root / 'local/kbacorporate').rglob('*') if p.is_file()}
    if actual_paths != set(paths):
        errors.append('Corporate source has unlisted or missing files')
    return errors, allowed


if __name__ == '__main__':
    errors, _ = check_source()
    print(json.dumps({'status': 'FAIL' if errors else 'PASS', 'errors': errors}))
    raise SystemExit(bool(errors))
