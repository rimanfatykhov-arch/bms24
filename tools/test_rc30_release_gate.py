"""Negative release controls; synthetic reports cannot establish release readiness."""
import copy
import hashlib
import tempfile
import unittest
import json
import zipfile
from unittest.mock import patch
from pathlib import Path
from rc30_release_gate import REQUIRED, evaluate


class ReleaseGateTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        (self.root / 'report.txt').write_bytes(b'synthetic unit-test fixture only')
        sha = hashlib.sha256((self.root / 'report.txt').read_bytes()).hexdigest()
        (self.root / 'candidate.zip').write_bytes(b'synthetic artifact; not a real release')
        artifact_sha = hashlib.sha256((self.root / 'candidate.zip').read_bytes()).hexdigest()
        self.bundle = {'release_sha': 'a' * 40, 'artifact_sha256': artifact_sha, 'artifact_path': 'candidate.zip',
                       'bms_version': 2026111002, 'corporate_version': 2026102613,
                       'checks': {name: {'status': 'PASS', 'release_sha': 'a' * 40,
                           'artifact_sha256': artifact_sha, 'path': 'report.txt', 'sha256': sha}
                           for name in REQUIRED}}

    def test_complete_bound_bundle(self):
        result = evaluate(self.bundle, self.root)
        self.assertEqual('EVIDENCE_COMPLETE', result['evidence_status'])
        self.assertEqual('HOLD', result['decision'])

    def test_every_required_gate_is_fail_closed(self):
        for name in REQUIRED:
            for status in ('FAIL', 'NOT RUN', 'SKIPPED', None):
                with self.subTest(gate=name, status=status):
                    bundle = copy.deepcopy(self.bundle)
                    bundle['checks'][name]['status'] = status
                    self.assertEqual('INCOMPLETE', evaluate(bundle, self.root)['evidence_status'])
            bundle = copy.deepcopy(self.bundle)
            del bundle['checks'][name]
            self.assertEqual('INCOMPLETE', evaluate(bundle, self.root)['evidence_status'])

    def test_wrong_candidate_or_artifact(self):
        for key, value in [('release_sha', 'c' * 40), ('artifact_sha256', 'c' * 64)]:
            bundle = copy.deepcopy(self.bundle)
            bundle['checks']['critical_acl'][key] = value
            self.assertEqual('INCOMPLETE', evaluate(bundle, self.root)['evidence_status'])

    def test_corporate_substitution_rejected(self):
        self.bundle['corporate_version'] = 2026110901
        self.assertEqual('INCOMPLETE', evaluate(self.bundle, self.root)['evidence_status'])

    def test_actual_artifact_required(self):
        (self.root / 'candidate.zip').write_bytes(b'tampered')
        self.assertEqual('INCOMPLETE', evaluate(self.bundle, self.root)['evidence_status'])
        (self.root / 'candidate.zip').unlink()
        self.assertEqual('INCOMPLETE', evaluate(self.bundle, self.root)['evidence_status'])

    def test_repaired_corporate_requires_exact_zip_and_lineage(self):
        self.bundle['corporate_version'] = 2026111004
        self.assertEqual('INCOMPLETE', evaluate(self.bundle, self.root)['evidence_status'])
        manifest = self.root / 'reviewed-manifest.json'
        manifest.write_text(json.dumps({'base_sha': 'b' * 40, 'patch_sha256': 'c' * 64,
            'files': {'acl.php': hashlib.sha256(b'approved ACL').hexdigest()}}))
        artifact = self.root / 'corporate.zip'
        info = zipfile.ZipInfo('kbacorporate/acl.php')
        info.external_attr = 0o100644 << 16
        with zipfile.ZipFile(artifact, 'w') as archive:
            archive.writestr(info, b'approved ACL')
        self.bundle.update(corporate_artifact_path='corporate.zip',
            corporate_artifact_sha256=hashlib.sha256(artifact.read_bytes()).hexdigest(),
            corporate_base_sha='b' * 40, corporate_patch_sha256='c' * 64)
        with patch('rc30_release_gate.CORPORATE_MANIFEST', manifest):
            self.assertEqual('EVIDENCE_COMPLETE', evaluate(self.bundle, self.root)['evidence_status'])
            self.assertEqual('HOLD', evaluate(self.bundle, self.root)['decision'])
            # Identical bytes and declared digest cannot authorize a symlink entry.
            info.external_attr = 0o120777 << 16
            with zipfile.ZipFile(artifact, 'w') as archive:
                archive.writestr(info, b'approved ACL')
            self.bundle['corporate_artifact_sha256'] = hashlib.sha256(artifact.read_bytes()).hexdigest()
            self.assertEqual('INCOMPLETE', evaluate(self.bundle, self.root)['evidence_status'])
            # Updating the zip digest cannot authorize modified source/permissions.
            info.external_attr = 0o100644 << 16
            with zipfile.ZipFile(artifact, 'w') as archive:
                archive.writestr(info, b'weakened ACL')
            self.bundle['corporate_artifact_sha256'] = hashlib.sha256(artifact.read_bytes()).hexdigest()
            self.assertEqual('INCOMPLETE', evaluate(self.bundle, self.root)['evidence_status'])
    def test_unavailable_tampered_or_external_report(self):
        for patch in ({'path': 'missing'}, {'path': '../report.txt'}, {'sha256': '0' * 64}):
            bundle = copy.deepcopy(self.bundle)
            bundle['checks']['isolated_restore'].update(patch)
            self.assertEqual('INCOMPLETE', evaluate(bundle, self.root)['evidence_status'])


if __name__ == '__main__':
    unittest.main()
