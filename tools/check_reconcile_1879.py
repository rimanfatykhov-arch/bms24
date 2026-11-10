#!/usr/bin/env python3
"""Source preservation and dependency gate; never connects to Moodle or a DB."""
import json
from check_corporate_api126 import check_source
import re
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
BASE = '740fce16db15bb3551071cc11fe584c680fd738d'
REVIEWED_UPDATES = {'.github/workflows/prepare-release.yml', 'docs/00_MASTER_CONTEXT.md'}
# Issue63 adds publication governance without discarding the retained main docs.
# Exact reviewed blobs, not a blanket exemption for future edits.
REVIEWED_DOC_CONTENT = {
    'docs/DEVELOPMENT_WORKFLOW.md': '555a050f9edb7a513b55d6a5e22063eaf768f94b',
    'docs/05_RELEASE_QA_STANDARD.md': '47b200efa4aefa2f062874afdc78e3e58848d1f4',
}

def check(root=ROOT):
    errors, corporate_updates = check_source(root)
    data = subprocess.check_output(['git', '-C', str(root), 'ls-tree', '-r', '-z', BASE])
    baseline = {}
    for row in data.split(b'\0'):
        if row:
            meta, path = row.split(b'\t', 1)
            baseline[path.decode()] = meta.decode().split()[2]
    inventory = json.loads((root / 'docs/reconcile/pr8-deletion-inventory.json').read_text(encoding='utf-8'))
    deleted = inventory['deletions']
    if len(deleted) != 2078 or len({r['path'] for r in deleted}) != len(deleted):
        errors.append('incomplete or duplicate import deletion inventory')
    for path in baseline:
        file = root / path
        if not file.is_file() or file.is_symlink():
            errors.append('missing or unsafe main path: ' + path)
    protected = {p for p in baseline if p.startswith('local/') and not p.startswith('local/kbacompany/')}
    protected.update(r['path'] for r in deleted)
    protected.difference_update(REVIEWED_UPDATES | corporate_updates)
    # Batch hashing preserves attributes while avoiding one process per file.
    paths = sorted(p for p in protected if (root / p).is_file() and not (root / p).is_symlink())
    hashes = subprocess.check_output(['git', '-C', str(root), 'hash-object', '--stdin-paths'],
        input=('\n'.join(paths)+'\n').encode()).decode().splitlines()
    for path, actual in zip(paths, hashes):
        if actual != REVIEWED_DOC_CONTENT.get(path, baseline[path]):
            errors.append('protected main content changed: ' + path)
    for row in deleted:
        expected = 'preserve_with_reviewed_update' if row['path'] in REVIEWED_UPDATES | REVIEWED_DOC_CONTENT.keys() else 'preserve_main'
        if row['decision'] != expected or row['main_blob'] != baseline.get(row['path']):
            errors.append('invalid preservation decision: ' + row['path'])
    deps = json.loads((root / 'docs/reconcile/plugin-class-dependencies.json').read_text(encoding='utf-8'))
    for dep in deps['references']:
        if dep['source_present'] and not (root / dep['source_path']).is_file():
            errors.append('missing inventoried dependency: ' + dep['source_path'])
    version = (root / 'local/kbacompany/version.php').read_text(encoding='utf-8')
    service = (root / 'local/kbacompany/classes/local/task_rc30_acceptance_service.php').read_text(encoding='utf-8')
    if not re.search(r'\$plugin->version\s*=\s*2026111002;', version) or not re.search(r'EXPECTED_PLUGIN_VERSION\s*=\s*2026111002;', service):
        errors.append('candidate/acceptance version drift')
    return errors

if __name__ == '__main__':
    errors = check()
    print(json.dumps({'status': 'FAIL' if errors else 'PASS', 'errors': errors,
                      'scope': 'source preservation only; not runtime dependency or tenant acceptance'}))
    raise SystemExit(1 if errors else 0)
