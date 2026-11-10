"""Validate a private release evidence bundle. Never deploys or grants approval."""
import argparse
import hashlib
import json
import re
import zipfile
import stat
from pathlib import Path

CORPORATE_MANIFEST = Path(__file__).resolve().parents[1] / 'release/corporate2613/manifest.json'


def verify_repaired_corporate(bundle, root):
    """A numeric version cannot substitute0901 or a different2613 feature tree."""
    relative = bundle['corporate_artifact_path']
    if not isinstance(relative, str) or Path(relative).is_absolute():
        raise ValueError('relative Corporate artifact required')
    artifact = (root / relative).resolve(strict=True)
    artifact.relative_to(root)
    if hashlib.sha256(artifact.read_bytes()).hexdigest() != bundle['corporate_artifact_sha256']:
        raise ValueError('Corporate artifact hash mismatch')
    manifest = json.loads(CORPORATE_MANIFEST.read_text())
    if bundle.get('corporate_base_sha') != manifest['base_sha'] or bundle.get('corporate_patch_sha256') != manifest['patch_sha256']:
        raise ValueError('Corporate lineage mismatch')
    with zipfile.ZipFile(artifact) as archive:
        expected = {'kbacorporate/' + p: value for p, value in manifest['files'].items()}
        if sorted(archive.namelist()) != sorted(expected):
            raise ValueError('Corporate file inventory mismatch')
        for name, sha in expected.items():
            if not stat.S_ISREG(archive.getinfo(name).external_attr >> 16):
                raise ValueError('Corporate archive entry is not a regular file')
            if hashlib.sha256(archive.read(name)).hexdigest() != sha:
                raise ValueError('Corporate source content mismatch')

REQUIRED = (
    'ci', 'artifact', 'corporate2613_identity', 'production1878_identity',
    'backup_integrity', 'isolated_restore', 'upgrade_rehearsal', 'rollback_rehearsal',
    'decimal_preflight_after_quiesce', 'data_preservation', 'browser_acceptance',
    'critical_acl', 'rto_rpo', 'independent_review', 'owner_publication_approval',
) + tuple(f'{engine}.{check}' for engine in ('mariadb1011', 'percona80') for check in (
    'fresh', 'upgrade1878', 'schema', 'bms_full', 'corporate2613_full',
    'learning_queues', 'documents_membership', 'company_isolation', 'diagnostics',
))


def evaluate(bundle, root):
    root = Path(root).resolve()
    blockers = []
    release = bundle.get('release_sha', '')
    artifact = bundle.get('artifact_sha256', '')
    if not isinstance(release, str) or not re.fullmatch(r'[0-9a-f]{40}', release):
        blockers.append('invalid release SHA')
    if not isinstance(artifact, str) or not re.fullmatch(r'[0-9a-f]{64}', artifact):
        blockers.append('invalid artifact SHA256')
    try:
        artifact_path = bundle['artifact_path']
        if not isinstance(artifact_path, str) or Path(artifact_path).is_absolute():
            raise ValueError('relative artifact path required')
        artifact_file = (root / artifact_path).resolve(strict=True)
        artifact_file.relative_to(root)
        if hashlib.sha256(artifact_file.read_bytes()).hexdigest() != artifact:
            raise ValueError('artifact digest mismatch')
    except (OSError, KeyError, TypeError, ValueError):
        blockers.append('missing or mismatched actual artifact')
    if bundle.get('bms_version') != 2026111002 or bundle.get('corporate_version') not in (2026102613, 2026111003):
        blockers.append('unexpected dependency versions')
    if bundle.get('corporate_version') == 2026111003:
        try:
            verify_repaired_corporate(bundle, root)
        except (OSError, ValueError, KeyError, TypeError, zipfile.BadZipFile):
            blockers.append('missing or mismatched repaired Corporate artifact/lineage')
    checks = bundle.get('checks', {})
    if not isinstance(checks, dict):
        checks = {}
    for name in REQUIRED:
        evidence = checks.get(name, {})
        if not isinstance(evidence, dict) or evidence.get('status') != 'PASS':
            blockers.append(name + ': not PASS')
            continue
        if evidence.get('release_sha') != release or evidence.get('artifact_sha256') != artifact:
            blockers.append(name + ': evidence belongs to another candidate')
        try:
            relative = evidence['path']
            if not isinstance(relative, str) or Path(relative).is_absolute():
                raise ValueError('relative path required')
            path = (root / relative).resolve(strict=True)
            path.relative_to(root)
            if not path.is_file() or path == root:
                raise ValueError('evidence file required')
            digest = hashlib.sha256(path.read_bytes()).hexdigest()
            if digest != evidence.get('sha256'):
                raise ValueError('evidence digest mismatch')
        except (OSError, ValueError, KeyError, TypeError):
            blockers.append(name + ': missing or invalid evidence file/hash')
    return {'decision': 'HOLD', 'evidence_status': 'INCOMPLETE' if blockers else 'EVIDENCE_COMPLETE',
            'review': 'Independent validation and owner decision required; no automatic GO', 'blockers': blockers,
            'release_sha': release, 'artifact_sha256': artifact,
            'scope': 'Evidence completeness and identity only; independent review must validate report contents. No deployment executed.'}


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('bundle', type=Path)
    args = parser.parse_args()
    try:
        result = evaluate(json.loads(args.bundle.read_text(encoding='utf-8')), args.bundle.parent)
    except (OSError, ValueError, AttributeError, TypeError):
        result = {'decision': 'HOLD', 'blockers': ['invalid evidence bundle']}
    print(json.dumps(result, ensure_ascii=False, indent=2))
    raise SystemExit(0 if result.get('evidence_status') == 'EVIDENCE_COMPLETE' else 1)
