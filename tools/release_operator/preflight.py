"""Offline, read-only release preflight. PASS never authorizes a service operation."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import stat
import time

from .package_guard import validate_zip, validate_changes


class Rejected(ValueError):
    pass


def need(condition, code):
    if not condition:
        raise Rejected(code)


def obj(value, keys):
    need(type(value) is dict and set(value) == set(keys.split()), 'CONFIG_KEYS')


def integer(value, minimum=0):
    need(type(value) is int and minimum <= value <= 2**63 - 1, 'INTEGER_REQUIRED')
    return value


def sha(value, length=64):
    need(type(value) is str and re.fullmatch('[0-9a-f]{%d}' % length, value), 'HASH_FORMAT')


def digest(data):
    return hashlib.sha256(data).hexdigest()


def pairs(rows):
    result = {}
    for key, value in rows:
        need(key not in result, 'DUPLICATE_JSON_KEY')
        result[key] = value
    return result


def read_json(path):
    return json.loads(path.read_text(encoding='utf-8'), object_pairs_hook=pairs,
                      parse_constant=lambda _: (_ for _ in ()).throw(Rejected('NONFINITE_JSON')))


def local_path(value):
    need(type(value) is str and value and not value.startswith(('\\\\', '//')),
         'LOCAL_ABSOLUTE_PATH_REQUIRED')
    path = Path(value)
    need(path.is_absolute() and '..' not in path.parts and '\x00' not in value,
         'PATH_TRAVERSAL')
    # Reject aliases before resolving; Windows junctions/reparse points too.
    for part in (path, *path.parents):
        st = part.lstat()
        need(not stat.S_ISLNK(st.st_mode) and not getattr(st, 'st_file_attributes', 0) & 1024,
             'PATH_LINK')
    return path.resolve(strict=True)


def under(path, root):
    return path == root or root in path.parents


def tree_hash(path):
    rows = []
    for entry in sorted(path.rglob('*')):
        st = entry.lstat()
        need(not stat.S_ISLNK(st.st_mode) and not getattr(st, 'st_file_attributes', 0) & 1024,
             'TREE_LINK')
        if stat.S_ISREG(st.st_mode):
            rows.append(entry.relative_to(path).as_posix() + ':' + digest(entry.read_bytes()))
        else:
            need(stat.S_ISDIR(st.st_mode), 'TREE_SPECIAL_FILE')
    need(rows, 'EMPTY_TREE')
    return digest('\n'.join(sorted(rows)).encode())


def check(config, now=None):
    now = int(time.time()) if now is None else integer(now)
    obj(config, 'schema_version release_kind reviewed_policy paths package baseline corporate window capacity storage recovery')
    need(type(config['schema_version']) is int and config['schema_version'] == 1, 'SCHEMA_VERSION')
    need(config['release_kind'] == 'code_only', 'RELEASE_KIND_UNSUPPORTED')
    raw = config['paths']
    obj(raw, 'allow_roots site data backup private_work test')
    need(type(raw['allow_roots']) is list and raw['allow_roots'], 'ALLOW_ROOTS')
    roots = [local_path(x) for x in raw['allow_roots']]
    need(len(set(roots)) == len(roots), 'DUPLICATE_ROOT')
    paths = {k: local_path(raw[k]) for k in ('site', 'data', 'backup', 'private_work', 'test')}
    for path in paths.values():
        need(path.is_dir() and any(under(path, root) and path != root for root in roots), 'PATH_OUTSIDE_ROOT')
    for name, path in paths.items():
        for other, p in paths.items():
            if name != other:
                need(not under(path, p), 'PATH_OVERLAP')
    # Refuse publicly writable/readable backup workspace on POSIX.
    if os.name == 'posix':
        for name in ('backup', 'private_work'):
            need(paths[name].stat().st_mode & 0o077 == 0, 'PRIVATE_MODE')

    w = config['window']
    obj(w, 'start_utc end_utc operation_seconds rollback_reserve_seconds')
    for value in w.values():
        integer(value, 1)
    need(w['start_utc'] <= now < w['end_utc'], 'WINDOW_NOT_CURRENT')
    need(now + w['operation_seconds'] + w['rollback_reserve_seconds'] <= w['end_utc'], 'DEADLINE_RESERVE')

    capacity = config['capacity']
    obj(capacity, 'account test')
    observations = {}
    for name, path in [('account', paths['backup']), ('test', paths['test'])]:
        c = capacity[name]
        obj(c, 'volume_device expected_uid require_linux_uid quota_source observed_utc ttl_seconds free_bytes free_inodes reserve_bytes reserve_inodes growth_bytes growth_inodes phases')
        for field in ('volume_device', 'observed_utc', 'ttl_seconds', 'free_bytes', 'free_inodes',
                      'reserve_bytes', 'reserve_inodes', 'growth_bytes', 'growth_inodes'):
            if field == 'volume_device':
                need(type(c[field]) is int and 0 <= c[field] < 2**64, 'VOLUME_FORMAT')
            else:
                integer(c[field], 1 if field == 'ttl_seconds' else 0)
        need(c['quota_source'] in ('hosting_panel', 'filesystem_quota'), 'QUOTA_SOURCE')
        need(0 <= now - c['observed_utc'] <= c['ttl_seconds'], 'STALE_QUOTA')
        need(type(c['require_linux_uid']) is bool, 'UID_POLICY')
        integer(c['expected_uid'])
        if hasattr(os, 'getuid'):
            need(os.getuid() == c['expected_uid'] == path.stat().st_uid, 'UID_MISMATCH')
        else:
            need(not c['require_linux_uid'], 'LINUX_UID_UNAVAILABLE')
        need(path.stat().st_dev == c['volume_device'], 'VOLUME_MISMATCH')
        need(type(c['phases']) is list and c['phases'], 'PHASES_REQUIRED')
        names = set()
        local_bytes = shutil.disk_usage(path).free
        local_inodes = os.statvfs(path).f_favail if hasattr(os, 'statvfs') else None
        for phase in c['phases']:
            obj(phase, 'name peak_bytes peak_inodes')
            need(type(phase['name']) is str and re.fullmatch('[a-z][a-z0-9_]{0,63}', phase['name'])
                 and phase['name'] not in names, 'PHASE_NAME')
            names.add(phase['name'])
            integer(phase['peak_bytes']); integer(phase['peak_inodes'])
            need(phase['peak_bytes'] + c['reserve_bytes'] + c['growth_bytes'] <= min(c['free_bytes'], local_bytes), 'SPACE_BYTES')
            need(phase['peak_inodes'] + c['reserve_inodes'] + c['growth_inodes'] <= c['free_inodes'], 'SPACE_INODES')
            if local_inodes is not None:
                need(phase['peak_inodes'] + c['reserve_inodes'] + c['growth_inodes'] <= local_inodes, 'FILESYSTEM_INODES')
        need(names == {'capture', 'copy', 'restore', 'install', 'rollback'}, 'PHASE_SET')
        observations[name] = {'filesystem_bytes_checked': True, 'filesystem_inodes_checked': local_inodes is not None,
                              'linux_uid_checked': hasattr(os, 'getuid')}

    for name in ('site', 'data', 'private_work'):
        need(paths[name].stat().st_dev == capacity['account']['volume_device'], 'ACCOUNT_VOLUME_MISMATCH')
    if capacity['account']['volume_device'] == capacity['test']['volume_device']:
        # Two estimates cannot each consume the same free filesystem capacity.
        peaks = [max(x['peak_bytes'] for x in capacity[n]['phases']) + capacity[n]['reserve_bytes'] + capacity[n]['growth_bytes'] for n in ('account','test')]
        need(sum(peaks) <= shutil.disk_usage(paths['backup']).free, 'SHARED_VOLUME_SPACE')
        if hasattr(os, 'statvfs'):
            inode_peaks = [max(x['peak_inodes'] for x in capacity[n]['phases']) + capacity[n]['reserve_inodes'] + capacity[n]['growth_inodes'] for n in ('account','test')]
            need(sum(inode_peaks) <= os.statvfs(paths['backup']).f_favail, 'SHARED_VOLUME_INODES')

    package = config['package']
    obj(package, 'zip manifest zip_sha256 manifest_sha256 head git_tree content_tree_sha256 entries max_unpacked_bytes')
    for name in ('zip_sha256', 'manifest_sha256', 'content_tree_sha256'):
        sha(package[name])
    sha(package['head'], 40); sha(package['git_tree'], 40)
    integer(package['entries'], 1); integer(package['max_unpacked_bytes'], 1)
    files = {k: local_path(package[k]) for k in ('zip', 'manifest')}
    need(files['zip'] != files['manifest'], 'PACKAGE_PATH_ALIAS')
    for path in files.values():
        need(path.is_file() and under(path, paths['private_work']), 'PACKAGE_OUTSIDE_WORK')
    need(digest(files['zip'].read_bytes()) == package['zip_sha256'], 'ZIP_HASH_DRIFT')
    need(digest(files['manifest'].read_bytes()) == package['manifest_sha256'], 'MANIFEST_HASH_DRIFT')
    manifest = read_json(files['manifest'])
    need(manifest.get('source', {}).get('pr_head') == package['head'] and
         manifest.get('source', {}).get('tree') == package['git_tree'], 'PACKAGE_IDENTITY')
    need(manifest.get('artifact', {}).get('zip_sha256') == package['zip_sha256'], 'MANIFEST_ZIP_HASH')
    import zipfile
    with zipfile.ZipFile(files['zip']) as archive:
        need(sum(x.file_size for x in archive.infolist()) <= package['max_unpacked_bytes'], 'ZIP_SIZE_LIMIT')
    content = validate_zip(files['zip'], manifest, package['entries'])
    rows = [n.removeprefix('kbacompany/') + ':' + digest(b) for n, b in content.items()]
    need(digest('\n'.join(sorted(rows)).encode()) == package['content_tree_sha256'], 'CONTENT_TREE_DRIFT')
    for name in ('baseline', 'corporate'):
        item = config[name]
        obj(item, 'relative_path tree_sha256')
        relative = item['relative_path']
        need(type(relative) is str and re.fullmatch(r'local/[a-z][a-z0-9_]+', relative), 'COMPONENT_PATH')
        need(relative == ('local/kbacompany' if name == 'baseline' else 'local/kbacorporate'), 'COMPONENT_NAME')
        sha(item['tree_sha256'])
        component = local_path(str(paths['site'] / relative))
        need(tree_hash(component) == item['tree_sha256'], name.upper() + '_TREE_DRIFT')

    policy = config['reviewed_policy']
    obj(policy, 'path sha256')
    sha(policy['sha256'])
    policy_path = local_path(policy['path'])
    need(under(policy_path, paths['private_work']) and policy_path.is_file(), 'POLICY_PATH')
    need(digest(policy_path.read_bytes()) == policy['sha256'], 'POLICY_HASH')
    reviewed = read_json(policy_path)
    need(reviewed.get('candidate_head') == package['head'], 'POLICY_HEAD')
    before = {x.relative_to(paths['site']/'local/kbacompany').as_posix(): digest(x.read_bytes())
              for x in (paths['site']/'local/kbacompany').rglob('*') if x.is_file()}
    after = {n.removeprefix('kbacompany/'): digest(b) for n, b in content.items()}
    validate_changes(before, after, reviewed)

    s = config['storage']
    obj(s, 'copies encrypted plaintext_owner_decision key_recovery_proof')
    need(s['copies'] == ['production', 'test'], 'STORAGE_COPIES')
    need(type(s['encrypted']) is bool, 'ENCRYPTION_POLICY')
    if s['encrypted']:
        need(s['plaintext_owner_decision'] is None, 'PLAINTEXT_POLICY')
        proof = s['key_recovery_proof']
        obj(proof, 'path sha256 observed_utc ttl_seconds')
        sha(proof['sha256']); integer(proof['observed_utc']); integer(proof['ttl_seconds'], 1)
        need(0 <= now - proof['observed_utc'] <= proof['ttl_seconds'], 'KEY_PROOF_STALE')
        p = local_path(proof['path'])
        need(under(p, paths['private_work']) and p.is_file() and p.stat().st_size > 0, 'KEY_PROOF_PATH')
        need(digest(p.read_bytes()) == proof['sha256'], 'KEY_PROOF_HASH')
    else:
        need(s['key_recovery_proof'] is None, 'UNEXPECTED_KEY_PROOF')
        need(type(s['plaintext_owner_decision']) is str and bool(s['plaintext_owner_decision'].strip()), 'PLAINTEXT_OWNER_DECISION')

    r = config['recovery']
    obj(r, 'observed_utc ttl_seconds private_access outbound_deny separate_credentials separate_volumes cron_disabled queues_disabled integrations_disabled')
    integer(r['observed_utc']); integer(r['ttl_seconds'], 1)
    need(0 <= now - r['observed_utc'] <= r['ttl_seconds'], 'RECOVERY_INVENTORY_STALE')
    need(all(r[k] is True for k in r if k not in ('observed_utc', 'ttl_seconds')), 'RECOVERY_ISOLATION_INVENTORY')
    return {'capacity_observations': observations, 'package_entries': len(content),
            'recovery_isolation': 'INVENTORY_ONLY_NOT_LIVE_VERIFIED',
            'key_recovery': 'EVIDENCE_HASH_ONLY_NOT_REHEARSED' if s['encrypted'] else 'NOT_APPLICABLE',
            'git_tree': 'MANIFEST_IDENTITY_ONLY; content SHA256 verified separately'}


def run(path):
    result = {'status': 'FAIL', 'scope': 'PREFLIGHT_ONLY', 'approval_required': True,
              'live_execute_supported': False, 'errors': []}
    try:
        result['checks'] = check(read_json(local_path(path)))
        result['status'] = 'PASS'
    except Rejected as error:
        result['errors'] = [str(error)]
    except Exception:
        # Exceptions may contain private paths, filenames, content or credentials.
        result['errors'] = ['INPUT_OR_PACKAGE_INVALID']
    return result


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--config', required=True)
    args = parser.parse_args()
    result = run(args.config)
    print(json.dumps(result, sort_keys=True))
    return 0 if result['status'] == 'PASS' else 1


if __name__ == '__main__':
    raise SystemExit(main())
