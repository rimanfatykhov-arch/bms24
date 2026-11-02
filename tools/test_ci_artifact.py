#!/usr/bin/env python3
"""Isolated GitHub candidate artifact verification regression tests."""
from __future__ import annotations
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
import zipfile

ROOT = Path(__file__).resolve().parents[1]


def mod(name):
    spec = importlib.util.spec_from_file_location(name, ROOT / 'tools' / (name + '.py'))
    obj = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(obj)
    return obj

build = mod('build_install')
verify = mod('verify_install')


class CandidateTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.base = Path(self.tmp.name)
        self.source = self.base / 'plugin'
        (self.source / 'cli').mkdir(parents=True)
        (self.source / 'version.php').write_text('<?php $plugin->version = 2026101877;')
        (self.source / 'app.php').write_text('<?php echo 1;')
        (self.source / 'cli/check_example.py').write_text('print(1)')
        (self.source / 'cli/test_example.py').write_text('print(1)')
        self.zip = self.base / 'candidate.zip'
        self.manifest = self.base / 'manifest.json'
        build.build(self.source, self.zip, self.manifest)

    def test_production_zip_excludes_python(self):
        with zipfile.ZipFile(self.zip) as zf:
            self.assertNotIn('kbacompany/cli/check_example.py', zf.namelist())
            self.assertIn('kbacompany/app.php', zf.namelist())

    def test_valid_archive_and_manifest(self):
        self.assertEqual(verify.verify(self.zip, self.manifest, 2026101877)['files'], 2)

    def test_wrong_version_fails(self):
        with self.assertRaises(ValueError):
            verify.verify(self.zip, self.manifest, 2026101873)

    def test_manifest_tamper_fails(self):
        obj=json.loads(self.manifest.read_text())
        obj['entries'][0]['sha256']='0'*64
        self.manifest.write_text(json.dumps(obj))
        with self.assertRaises(ValueError):
            verify.verify(self.zip, self.manifest)

    def test_archive_byte_tamper_fails(self):
        with zipfile.ZipFile(self.zip, 'a') as zf:
            zf.writestr('kbacompany/extra.php', '<?php')
        with self.assertRaises(ValueError):
            verify.verify(self.zip, self.manifest)

    def test_traversal_rejected(self):
        with zipfile.ZipFile(self.zip, 'a') as zf:
            zf.writestr('kbacompany/../../etc/shadow', 'data')
        with self.assertRaises(ValueError):
            verify.verify(self.zip)

    def test_duplicate_member_rejected(self):
        import warnings
        with warnings.catch_warnings():
            warnings.simplefilter('ignore')
            with zipfile.ZipFile(self.zip, 'a') as zf:
                zf.writestr('kbacompany/version.php', '<?php')
        with self.assertRaises(ValueError):
            verify.verify(self.zip)

    def test_symlink_rejected(self):
        with zipfile.ZipFile(self.zip, 'a') as zf:
            info=zipfile.ZipInfo('kbacompany/link.php')
            info.create_system=3
            info.external_attr=0o120777 << 16
            zf.writestr(info, 'version.php')
        with self.assertRaises(ValueError):
            verify.verify(self.zip)

    def test_sensitive_data_rejected(self):
        (self.source / '.env').write_text('SHOULD NOT BE ARCHIVED')
        with self.assertRaises(ValueError):
            build.build(self.source, self.base / 'new.zip')

    def test_deterministic_package(self):
        other=self.base / 'other.zip'
        build.build(self.source, other)
        self.assertEqual(self.zip.read_bytes(), other.read_bytes())

    def test_missing_manifest_fails(self):
        with self.assertRaises(OSError):
            verify.verify(self.zip, self.base / 'not_there.json')


if __name__=='__main__':
    unittest.main(verbosity=2)
