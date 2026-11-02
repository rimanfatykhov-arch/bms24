#!/usr/bin/env python3
"""Conservative PHP 8.1 compatibility gate for BMS24 source.

This does not replace lint with the actual PHP 8.1 interpreter. Set
PHP81_BIN to obtain authoritative per-file syntax verification.
"""
from __future__ import annotations
import os
from pathlib import Path
import re
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[1]
PATTERNS = [
    ('PHP 8.2 readonly class', re.compile(r'\breadonly\s+class\s+\w+')),
    ('PHP 8.3 typed class constant', re.compile(r'\b(?:public|protected|private)\s+const\s+(?:\??[A-Za-z_\\][\w\\|?]*\s+)+\w+\s*=')),
    ('PHP 8.4 property hooks', re.compile(r'\b(?:public|protected|private)\s+(?:\??[A-Za-z_\\][\w\\|?]*\s+)?\$\w+\s*\{\s*(?:get|set)\s*(?:=>|\{)')),
    ('PHP 8.4 asymmetric visibility', re.compile(r'\b(?:public|protected|private)\(set\)')),
    ('PHP 8.4 new-expression property access', re.compile(r'(?<!\()\bnew\s+[A-Za-z_\\][\w\\]*\s*\([^()\n]*\)\s*->')),
]

def check_text(path: str, source: str) -> list[str]:
    # Scope to parsed source candidates. False positives should be reviewed,
    # not bypassed silently; avoid scanning documentation / release notes.
    return [f'{path}: {name}' for name, regex in PATTERNS if regex.search(source)]

def main() -> int:
    files = sorted(ROOT.rglob('*.php'))
    errors: list[str] = []
    for path in files:
        errors.extend(check_text(str(path.relative_to(ROOT)), path.read_text(encoding='utf-8', errors='replace')))
    binary = os.getenv('PHP81_BIN', '').strip()
    if binary:
        cp = subprocess.run([binary, '-r', 'echo PHP_MAJOR_VERSION,".",PHP_MINOR_VERSION;'], text=True, capture_output=True)
        if cp.returncode or cp.stdout.strip() != '8.1':
            errors.append('PHP81_BIN must point to PHP 8.1, got: '+(cp.stdout+cp.stderr).strip())
        else:
            for path in files:
                cp = subprocess.run([binary, '-l', str(path)], text=True, capture_output=True)
                if cp.returncode:
                    errors.append(f'{path.relative_to(ROOT)}: PHP 8.1 lint: '+(cp.stdout+cp.stderr).strip())
    if errors:
        print('\n'.join(errors[:30]), file=sys.stderr)
        return 1
    print(f'PHP 8.1 static syntax gate PASS: {len(files)} files; native PHP 8.1 lint: {"PASS" if binary else "NOT RUN (PHP81_BIN unset)"}')
    return 0

if __name__ == '__main__':
    sys.exit(main())
