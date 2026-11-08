#!/usr/bin/env python3
"""Positive/negative dependency regressions against actual Corporate source."""
import json
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest
from check_corporate_api126 import ROOT, check_source


class CorporateDependencyTest(unittest.TestCase):
    def test_current_source_and_real_bridge(self):
        self.assertEqual(check_source()[0], [])
        subprocess.run(['php', str(ROOT / 'tools/test_corporate_bridge126.php')], check=True)

    def test_old_bridge_rejected(self):
        old = subprocess.check_output(['git', 'show',
            '3f000449498ea3c81d563248d4c99a749bed1994:local/kbacorporate/classes/local/bridge.php'], cwd=ROOT)
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'bridge.php'
            path.write_bytes(old)
            result = subprocess.run(['php', str(ROOT / 'tools/test_corporate_bridge126.php'), str(path)],
                                    capture_output=True, text=True)
            self.assertNotEqual(result.returncode, 0)
            self.assertIn('Corporate API 1.26 required', result.stdout + result.stderr)

    def test_missing_modified_and_unlisted_source_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            shutil.copytree(ROOT / 'local/kbacorporate', root / 'local/kbacorporate')
            (root / 'docs/reconcile').mkdir(parents=True)
            shutil.copy2(ROOT / 'docs/reconcile/corporate-api126-source.json', root / 'docs/reconcile')
            self.assertEqual(check_source(root)[0], [])
            bridge = root / 'local/kbacorporate/classes/local/bridge.php'
            original = bridge.read_bytes()
            bridge.write_bytes(original + b'\n// drift\n')
            self.assertTrue(any('hash mismatch' in e for e in check_source(root)[0]))
            bridge.unlink()
            self.assertTrue(any('missing' in e for e in check_source(root)[0]))
            bridge.write_bytes(original)
            (root / 'local/kbacorporate/unreviewed.php').write_text('<?php')
            self.assertTrue(any('unlisted' in e for e in check_source(root)[0]))


if __name__ == '__main__':
    unittest.main()
