#!/usr/bin/env python3
"""Bind a tier report to the candidate it was produced for (RC32 review, Issue #82).

Writes identity.json next to the report: tier, target, head, base, profile, engine, the producing GitHub run/attempt/job
and the SHA256 of the exact report bytes. merge_gate.py refuses any report whose identity does not match the expected
candidate, this run, this tier/engine, or whose bytes differ from the stamped hash. A missing report is stamped as missing.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path


def main():
    ap = argparse.ArgumentParser()
    for k in ('tier', 'target', 'head', 'base', 'profile', 'report', 'out'):
        ap.add_argument('--' + k, required=True)
    ap.add_argument('--engine', default='')
    a = ap.parse_args()
    rp = Path(a.report)
    ident = {'tier': a.tier, 'target': a.target, 'head': a.head, 'base': a.base, 'profile': a.profile, 'engine': a.engine,
             'run_id': os.environ.get('GITHUB_RUN_ID', ''), 'run_attempt': os.environ.get('GITHUB_RUN_ATTEMPT', ''),
             'job': os.environ.get('GITHUB_JOB', ''), 'report': rp.name,
             'report_sha256': hashlib.sha256(rp.read_bytes()).hexdigest() if rp.is_file() else None}
    Path(a.out).parent.mkdir(parents=True, exist_ok=True)
    Path(a.out).write_text(json.dumps(ident, indent=1))
    print(json.dumps(ident))


if __name__ == '__main__':
    main()
