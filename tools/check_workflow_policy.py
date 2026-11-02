#!/usr/bin/env python3
"""Fail closed on common BMS24 CI privileges/triggers/deployment mistakes.

Static policy is defense in depth, NOT a substitute for GitHub branch protections
or a human review of changed workflow files.
"""
from __future__ import annotations
from pathlib import Path
import re
import sys

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = ROOT / '.github/workflows/bms24-ci.yml'

class WorkflowHold(ValueError):
    pass


def validate(text: str) -> None:
    # Security-critical contracts for this read-only CI pipeline only.
    required = [
        (r'(?m)^on:\s*$', 'missing triggers'),
        (r'(?m)^\s{2}pull_request:\s*$', 'missing PR trigger'),
        (r'(?m)^\s{2}push:\s*$', 'missing push trigger'),
        (r'(?m)^permissions:\s*\n\s{2}contents:\s*read\s*$', 'workflow must be read-only'),
        (r'(?m)^\s+- name: Build and verify deterministic candidate\s*$', 'missing ZIP verification step'),
        (r'(?m)^\s+- name: Upload candidate artifact \(PR and main\)\s*$', 'missing PR artifact upload'),
        (r"(?m)^\s+if: github.event_name == 'pull_request' \|\| \(github.event_name == 'push' && github.ref == 'refs/heads/main'\)\s*$", 'unsafe candidate publish condition'),
        (r'(?m)^\s+uses: actions/upload-artifact@v4\s*$', 'missing artifact upload action'),
        (r'(?m)^\s+retention-days:\s*14\s*$', 'missing artifact retention'),
    ]
    for expression, msg in required:
        if not re.search(expression, text):
            raise WorkflowHold(msg)
    # Reject risky triggers, token write access, external privilege escalation,
    # and any shell command that would deploy or modify a production server.
    forbidden = [
        r'(?m)^\s*pull_request_target\s*:',
        r'(?m)^\s*workflow_run\s*:',
        r'(?im)^\s*(contents|actions|deployments|issues|pull-requests):\s*write\s*$',
        r'(?im)persist-credentials:\s*true',
        r'\$\{\{\s*secrets\.',
        r'(?im)^\s*(?:-\s*)?(?:run:\s*)?(?:sudo\s+)?(?:scp|rsync|ssh)\s+',
        r'(?im)^\s*(?:-\s*)?(?:run:\s*)?(?:sudo\s+)?(?:kubectl|helm|terraform|ansible-playbook)\s+',
    ]
    for expression in forbidden:
        if re.search(expression, text):
            raise WorkflowHold('privileged or deployment workflow content is not allowed')
    if not re.search(r'--expected-version 2026101879', text):
        raise WorkflowHold('wrong expected package version')
    if not re.search(r'tools/test_business_dates_1876.py', text):
        raise WorkflowHold('missing regression for calendar dates')


def main() -> int:
    try:
        validate(WORKFLOW.read_text(encoding='utf-8'))
    except (WorkflowHold, OSError) as e:
        print('HOLD: ' + str(e), file=sys.stderr)
        return 2
    print('PASS: GitHub PR candidate policy, no deploy permissions')
    return 0

if __name__ == '__main__':
    raise SystemExit(main())
