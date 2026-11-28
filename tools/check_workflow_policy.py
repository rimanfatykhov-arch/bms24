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
PACKAGE_WORKFLOW = ROOT / '.github/workflows/pr90-disposable.yml'

class WorkflowHold(ValueError):
    pass


def validate(text: str, package: str | None = None) -> None:
    if package is None:
        package = PACKAGE_WORKFLOW.read_text(encoding='utf-8')
    # Security-critical contracts for this read-only CI pipeline only.
    required = [
        (r'(?m)^on:\s*$', 'missing triggers'),
        (r'(?m)^\s{2}pull_request:\s*$', 'missing PR trigger'),
        (r'(?m)^\s{2}push:\s*$', 'missing push trigger'),
        (r'(?m)^permissions:\s*\n\s{2}contents:\s*read\s*$', 'workflow must be read-only'),
        (r'(?m)^\s+- name: Build and verify deterministic candidate\s*$', 'missing ZIP verification step'),
    ]
    for expression, msg in required:
        if not re.search(expression, text):
            raise WorkflowHold(msg)
    if 'actions/upload-artifact@' in text:
        raise WorkflowHold('the static workflow must not publish a second candidate')
    package_job = package.split('\n  rehearsal:', 1)[0]
    for expression in (
        r'(?m)^\s{2}pull_request:\s*$',
        r'(?m)^\s{4}branches: \[main\]\s*$',
        r'(?m)^permissions:\s*\n\s{2}contents:\s*read\s*$',
        r'ref: \$\{\{ github\.event\.pull_request\.head\.sha \}\}',
        r'python tools/acceptance/pr90/package\.py',
        r'uses: actions/upload-artifact@v4',
        r'retention-days: 30',
    ):
        if not re.search(expression, package_job):
            raise WorkflowHold('missing exact-PR-head packaging or retained upload')
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
        if re.search(expression, text) or re.search(expression, package):
            raise WorkflowHold('privileged or deployment workflow content is not allowed')
    if not re.search(r'--expected-version 2026112500', text):
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
