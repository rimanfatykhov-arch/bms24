"""Real temporary JSON/ZIP/tree fixtures; never touches a service."""
import copy
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import time
import unittest
import zipfile

from tools.release_operator import preflight as p


class PreflightTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name).resolve()
        paths = {}
        for name in ('site', 'data', 'backup', 'private_work', 'test'):
            folder = self.root / name
            folder.mkdir(mode=0o700)
            paths[name] = str(folder)
        for name in ('kbacompany', 'kbacorporate'):
            folder = self.root / 'site/local' / name
            folder.mkdir(parents=True)
            (folder / 'version.php').write_bytes(b'baseline')
            (folder / 'feature.php').write_bytes(b'before')
        self.zip = self.root / 'private_work/package.zip'
        with zipfile.ZipFile(self.zip, 'w') as z:
            z.writestr('kbacompany/version.php', b'baseline')
            z.writestr('kbacompany/feature.php', b'candidate')
        manifest = {'artifact': {'zip_sha256': p.digest(self.zip.read_bytes())},
                    'source': {'pr_head': 'a' * 40, 'tree': 'b' * 40},
                    'entries': [{'path': 'kbacompany/version.php', 'bytes': 8, 'sha256': p.digest(b'baseline')},
                                {'path': 'kbacompany/feature.php', 'bytes': 9, 'sha256': p.digest(b'candidate')}]}
        self.manifest = self.root / 'private_work/manifest.json'
        self.manifest.write_text(json.dumps(manifest))
        now = int(time.time())
        capacity = lambda name: {'volume_device': (self.root/name).stat().st_dev,
            'expected_uid': os.getuid() if hasattr(os, 'getuid') else 0,
            'require_linux_uid': os.name == 'posix', 'quota_source': 'hosting_panel',
            'observed_utc': now, 'ttl_seconds': 300, 'free_bytes': 10**8, 'free_inodes': 10000,
            'reserve_bytes': 1024, 'reserve_inodes': 10, 'growth_bytes': 1024,
            'growth_inodes': 10, 'phases': [{'name': n, 'peak_bytes': 2048, 'peak_inodes': 20} for n in ['capture','copy','restore','install','rollback']]}
        policy = self.root/'private_work/policy.json'
        policy.write_text(json.dumps({'schema': 1, 'candidate_head': 'a'*40, 'changes': [{'path': 'feature.php', 'before': p.digest(b'before'), 'after': p.digest(b'candidate')}]}))
        self.config = {'schema_version': 1, 'release_kind': 'code_only', 'reviewed_policy': {'path': str(policy), 'sha256': p.digest(policy.read_bytes())}, 'paths': {'allow_roots': [str(self.root)], **paths},
            'package': {'zip': str(self.zip), 'manifest': str(self.manifest),
                'zip_sha256': p.digest(self.zip.read_bytes()), 'manifest_sha256': p.digest(self.manifest.read_bytes()),
                'head': 'a'*40, 'git_tree': 'b'*40,
                'content_tree_sha256': p.digest(('feature.php:'+p.digest(b'candidate')+'\nversion.php:'+p.digest(b'baseline')).encode()),
                'entries': 2, 'max_unpacked_bytes': 1000},
            'baseline': {'relative_path': 'local/kbacompany', 'tree_sha256': p.tree_hash(self.root/'site/local/kbacompany')},
            'corporate': {'relative_path': 'local/kbacorporate', 'tree_sha256': p.tree_hash(self.root/'site/local/kbacorporate')},
            'window': {'start_utc': now-1, 'end_utc': now+600, 'operation_seconds': 100, 'rollback_reserve_seconds': 60},
            'capacity': {'account': capacity('backup'), 'test': capacity('test')},
            'storage': {'copies': ['production', 'test'], 'encrypted': False,
                'plaintext_owner_decision': 'synthetic fixture only', 'key_recovery_proof': None},
            'recovery': {'observed_utc': now, 'ttl_seconds': 300, **{k: True for k in
                ['private_access','outbound_deny','separate_credentials','separate_volumes',
                 'cron_disabled','queues_disabled','integrations_disabled']}}}
        self.path = self.root/'private_work/config.json'

    def result(self, config=None):
        self.path.write_text(json.dumps(config or self.config))
        return p.run(str(self.path))

    def test_positive_and_cli_optimized_parity(self):
        result = self.result()
        self.assertEqual(result['status'], 'PASS', result)
        self.assertFalse(result['live_execute_supported'])
        self.assertEqual(result['checks']['recovery_isolation'], 'INVENTORY_ONLY_NOT_LIVE_VERIFIED')
        for flags in ([], ['-O']):
            proc = subprocess.run([sys.executable, *flags, '-m', 'tools.release_operator.preflight',
                                   '--config', str(self.path)], capture_output=True, text=True)
            self.assertEqual(proc.returncode, 0, proc.stderr)
            self.assertEqual(json.loads(proc.stdout), result)

    def test_negative_matrix(self):
        cases = [
            (('schema_version',), True), (('unknown',), 1), (('release_kind',), 'schema_change'),
            (('window','end_utc'), None), (('window','operation_seconds'), True),
            (('window','end_utc'), int(time.time())+60),
            (('capacity','account','observed_utc'), 1),
            (('capacity','account','volume_device'), 999999999),
            (('capacity','account','free_bytes'), 1),
            (('capacity','test','free_inodes'), 1),
            (('capacity','test','quota_source'), 'df'),
            (('paths','data'), self.config['paths']['site']),
            (('paths','backup'), str(self.root/'private_work/../backup')),
            (('package','head'), 'c'*40), (('package','git_tree'), 'd'*40),
            (('package','zip_sha256'), '0'*64),
            (('package','content_tree_sha256'), '0'*64),
            (('package','max_unpacked_bytes'), 1),
            (('baseline','tree_sha256'), '0'*64),
            (('corporate','relative_path'), 'local/kbacompany'),
            (('storage','plaintext_owner_decision'), ''),
            (('storage','copies'), ['production']),
            (('storage','encrypted'), True),
            (('recovery','outbound_deny'), False),
            (('recovery','observed_utc'), 1),
        ]
        if hasattr(os, 'getuid'):
            cases.append((('capacity','account','expected_uid'), os.getuid()+1))
        else:
            cases.append((('capacity','account','require_linux_uid'), True))
        expected_codes = ['SCHEMA_VERSION','CONFIG_KEYS','RELEASE_KIND_UNSUPPORTED',
            'INTEGER_REQUIRED','INTEGER_REQUIRED','DEADLINE_RESERVE','STALE_QUOTA',
            'VOLUME_MISMATCH','SPACE_BYTES','SPACE_INODES','QUOTA_SOURCE','PATH_OVERLAP',
            'PATH_TRAVERSAL','PACKAGE_IDENTITY','PACKAGE_IDENTITY','ZIP_HASH_DRIFT',
            'CONTENT_TREE_DRIFT','ZIP_SIZE_LIMIT','BASELINE_TREE_DRIFT','COMPONENT_NAME',
            'PLAINTEXT_OWNER_DECISION','STORAGE_COPIES','PLAINTEXT_POLICY',
            'RECOVERY_ISOLATION_INVENTORY','RECOVERY_INVENTORY_STALE',
            'UID_MISMATCH' if hasattr(os,'getuid') else 'LINUX_UID_UNAVAILABLE']
        self.assertEqual(len(cases),len(expected_codes))
        for (keys, value), code in zip(cases,expected_codes):
            with self.subTest(keys=keys):
                c = copy.deepcopy(self.config); node = c
                for key in keys[:-1]: node = node[key]
                node[keys[-1]] = value
                result = self.result(c)
                self.assertEqual(result['status'], 'FAIL', result)
                self.assertEqual(result['errors'], [code])
                self.assertNotIn(str(self.root), json.dumps(result))

    def test_duplicate_json_and_cli_failure(self):
        self.path.write_text('{"schema_version":1,"schema_version":1}')
        for flags in ([], ['-O']):
            proc = subprocess.run([sys.executable, *flags, '-m','tools.release_operator.preflight',
                                   '--config',str(self.path)],capture_output=True,text=True)
            self.assertEqual(proc.returncode,1)
            self.assertEqual(json.loads(proc.stdout)['errors'], ['DUPLICATE_JSON_KEY'])

    def test_archive_traversal_and_duplicate(self):
        import warnings
        from tools.release_operator.package_guard import validate_zip
        original_zip = self.zip.read_bytes()
        original_manifest = json.loads(self.manifest.read_text())
        original_config = copy.deepcopy(self.config)
        for name in ('kbacompany/../secret', 'kbacompany/version.php'):
            with self.subTest(name=name), warnings.catch_warnings():
                warnings.simplefilter('ignore')
                self.zip.write_bytes(original_zip)
                self.config = copy.deepcopy(original_config)
                m = copy.deepcopy(original_manifest)
                if '..' in name:
                    with zipfile.ZipFile(self.zip) as z:
                        items = [(i.filename, z.read(i)) for i in z.infolist()]
                    replaced = items[0][0]
                    with zipfile.ZipFile(self.zip, 'w') as z:
                        for entry, content in items:
                            z.writestr(name if entry == replaced else entry, content)
                    for entry in m['entries']:
                        if entry['path'] == replaced: entry['path'] = name
                    expected_reason = 'noncanonical file path'
                else:
                    with zipfile.ZipFile(self.zip, 'a') as z: z.writestr(name,b'bad')
                    expected_reason = 'duplicate ZIP path'
                with self.assertRaisesRegex(ValueError, expected_reason):
                    validate_zip(self.zip, m, len(m['entries']))
                self.config['package']['zip_sha256'] = p.digest(self.zip.read_bytes())
                m['artifact']['zip_sha256']=self.config['package']['zip_sha256']
                self.manifest.write_text(json.dumps(m));self.config['package']['manifest_sha256']=p.digest(self.manifest.read_bytes())
                self.assertEqual(self.result()['status'],'FAIL')

    def test_drift_and_symlink(self):
        (self.root/'site/local/kbacorporate/version.php').write_bytes(b'drift')
        self.assertEqual(self.result()['errors'], ['CORPORATE_TREE_DRIFT'])
        if os.name == 'posix':
            (self.root/'site/local/kbacorporate/version.php').unlink()
            (self.root/'site/local/kbacorporate/version.php').symlink_to(self.zip)
            self.assertEqual(self.result()['errors'], ['TREE_LINK'])

    def test_protected_schema_and_policy_guards(self):
        from tools.release_operator.package_guard import validate_changes
        before = {'version.php': 'a'*64, 'db/install.xml': 'b'*64, 'feature.php': 'c'*64}
        policy = {'schema': 1, 'candidate_head': 'a'*40, 'changes': [
            {'path': 'feature.php', 'before': 'c'*64, 'after': 'd'*64}]}
        good = {**before, 'feature.php': 'd'*64}
        self.assertEqual(validate_changes(before,good,policy), ['feature.php'])
        for name in ('version.php', 'db/install.xml'):
            with self.subTest(name=name), self.assertRaises(ValueError):
                validate_changes(before,{**good,name:'e'*64},policy)
        with self.assertRaises(ValueError):
            validate_changes(before,{'version.php':'a'*64,'feature.php':'d'*64},policy)
        self.config['reviewed_policy']['sha256'] = '0'*64
        self.assertEqual(self.result()['errors'], ['POLICY_HASH'])

    def test_encrypted_proof_and_phases(self):
        proof = self.root/'private_work/key-proof.json'
        proof.write_text('{"fixture":"synthetic-only"}')
        self.config['storage'].update(encrypted=True, plaintext_owner_decision=None,
            key_recovery_proof={'path':str(proof),'sha256':p.digest(proof.read_bytes()),
                                'observed_utc':int(time.time()),'ttl_seconds':300})
        self.assertEqual(self.result()['status'],'PASS')
        proof.write_bytes(b'changed')
        self.assertEqual(self.result()['errors'],['KEY_PROOF_HASH'])
        self.config['storage']['key_recovery_proof']['sha256'] = p.digest(proof.read_bytes())
        for phases, expected in (([], 'PHASES_REQUIRED'),
                       ([{'name':'x','peak_bytes':0,'peak_inodes':0}], 'PHASE_SET'),
                       (self.config['capacity']['account']['phases']*2, 'PHASE_NAME')):
            self.config['capacity']['account']['phases']=phases
            self.assertEqual(self.result()['errors'],[expected])


if __name__ == '__main__':
    unittest.main()
