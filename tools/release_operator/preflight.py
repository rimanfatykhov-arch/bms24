#!/usr/bin/env python3
"""BMS24 Release Operator V0: read-only, fail-closed release request inspector.

This file has deliberately NO deploy, SSH, backup, restore, GitHub mutation, or
approval-verification capability. A valid request ALWAYS results in HOLD.
"""
from __future__ import annotations

import argparse
import json
import re
from pathlib import Path
from typing import Any


class InvalidRequest(ValueError):
    """The request cannot safely be interpreted."""


def require(condition: bool, message: str) -> None:
    if not condition:
        raise InvalidRequest(message)


def exact_fields(value: Any, fields: set[str], context: str) -> dict:
    require(isinstance(value, dict), context + ': object required')
    require(set(value) == fields, context + ': missing or unexpected fields')
    return value


def hex_sha(value: Any, length: int, label: str) -> None:
    require(isinstance(value, str) and re.fullmatch(r'[0-9a-f]{%d}' % length, value) is not None,
            label + ': expected lowercase hex digest of length ' + str(length))


def validate(request: Any) -> dict:
    """Validate an untrusted request. Never trust its claims as release evidence."""
    d = exact_fields(request, {'schema', 'release_id', 'repository', 'environment', 'mode',
                               'production_baseline', 'candidate', 'migration', 'authorization'}, 'request')
    require(d['schema'] == 'bms24.release_operator.request.v0', 'unknown request schema')
    require(isinstance(d['release_id'], str) and
            re.fullmatch(r'BMS24-[A-Z0-9][A-Z0-9-]{2,48}', d['release_id']) is not None,
            'invalid release_id')
    require(d['repository'] == 'rimanfatykhov-arch/bms24', 'wrong repository')
    require(d['environment'] in ('dev', 'staging', 'production'), 'invalid environment')
    require(d['mode'] == 'read_only_preflight', 'V0 cannot request execution')
    baseline = exact_fields(d['production_baseline'], {'source_sha', 'plugin_version'}, 'production_baseline')
    hex_sha(baseline['source_sha'], 40, 'production_baseline.source_sha')
    require(type(baseline['plugin_version']) is int and baseline['plugin_version'] > 0,
            'invalid baseline plugin_version')
    candidate = exact_fields(d['candidate'], {'pr_number', 'head_sha', 'ci_checkout_sha',
                                             'plugin_version', 'zip_sha256', 'manifest_sha256'}, 'candidate')
    require(type(candidate['pr_number']) is int and candidate['pr_number'] > 0, 'invalid candidate.pr_number')
    for key in ('head_sha', 'ci_checkout_sha'):
        hex_sha(candidate[key], 40, 'candidate.' + key)
    for key in ('zip_sha256', 'manifest_sha256'):
        hex_sha(candidate[key], 64, 'candidate.' + key)
    require(type(candidate['plugin_version']) is int and candidate['plugin_version'] >= baseline['plugin_version'],
            'candidate version lower than production baseline')
    migration = exact_fields(d['migration'], {'required', 'expected_new_tables', 'recovery'}, 'migration')
    require(type(migration['required']) is bool, 'migration.required must be boolean')
    require(type(migration['expected_new_tables']) is int and 0 <= migration['expected_new_tables'] <= 100,
            'invalid expected_new_tables')
    if migration['required']:
        require(candidate['plugin_version'] > baseline['plugin_version'],
                'schema migration must increment plugin version')
        require(migration['recovery'] == 'feature_off_forward_fix_or_separately_approved_restore',
                'schema migration cannot use code-only rollback')
    else:
        require(migration['expected_new_tables'] == 0, 'tables added without migration')
        require(migration['recovery'] in ('code_only_if_schema_unchanged',
                                          'feature_off_forward_fix_or_separately_approved_restore'),
                'invalid recovery method')
    authorization = exact_fields(d['authorization'], {'owner_approval', 'deployment_window'}, 'authorization')
    require(authorization['owner_approval'] == 'NOT_GRANTED', 'V0 cannot process owner approval')
    require(authorization['deployment_window'] is None, 'V0 cannot schedule a deployment window')
    return d


def inspect(request: Any) -> dict:
    """Return structured HOLD for valid requests and INVALID for malformed inputs."""
    try:
        d = validate(request)
    except (InvalidRequest, TypeError) as exc:
        return {'schema': 'bms24.release_operator.report.v0', 'status': 'INVALID',
                'deployable': False, 'reason': str(exc), 'actions_executed': []}
    return {
        'schema': 'bms24.release_operator.report.v0',
        'status': 'HOLD',
        'deployable': False,
        'release_id': d['release_id'],
        'environment': d['environment'],
        'candidate_sha': d['candidate']['head_sha'],
        'candidate_zip_sha256': d['candidate']['zip_sha256'],
        'declared_migration_required': d['migration']['required'],
        'evidence_verified': False,
        'actions_executed': [],
        'blockers': [
            'No authenticated server inventory or live baseline verification',
            'No independently verified CI/ZIP/manifest attestation in V0',
            'No tested continuous writer-pause, backup/restore, DDL recovery operator',
            'No verified single-operator lock or protected server execution channel',
            'Owner approval for exact candidate, target and window not granted',
        ],
        'next_step': 'Build and independently test a separate privileged executor; V0 must remain read-only.',
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--request', required=True, type=Path)
    parser.add_argument('--report', required=True, type=Path)
    args = parser.parse_args()
    try:
        request = json.loads(args.request.read_text(encoding='utf-8'))
    except (OSError, ValueError) as exc:
        result = {'schema': 'bms24.release_operator.report.v0', 'status': 'INVALID',
                  'deployable': False, 'reason': type(exc).__name__, 'actions_executed': []}
    else:
        result = inspect(request)
    args.report.parent.mkdir(parents=True, exist_ok=True)
    args.report.write_text(json.dumps(result, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
    print('BMS24 Release Operator V0: ' + result['status'] + ' (READ-ONLY; NOT DEPLOYED)')
    return 2 if result['status'] == 'INVALID' else 0


if __name__ == '__main__':
    raise SystemExit(main())
