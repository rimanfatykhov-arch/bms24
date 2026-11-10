"""Negative controls: a source identity is not just a plugin version number."""
import tempfile
import unittest
from pathlib import Path
from build_corporate2613 import digest, verify

class SourceIdentityTest(unittest.TestCase):
    def test_exact_source_passes(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            (root / 'version.php').write_bytes(b'correct-version')
            self.assertEqual(64, len(verify(root, {'version.php': digest(b'correct-version')})))

    def test_same_version_modified_runtime_is_rejected(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            (root / 'version.php').write_bytes(b'2613')
            (root / 'acl.php').write_bytes(b'changed')
            with self.assertRaises(ValueError):
                verify(root, {'version.php': digest(b'2613'), 'acl.php': digest(b'original')})

    def test_missing_and_extra_files_are_rejected(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            with self.assertRaises(ValueError):
                verify(root, {'required.php': digest(b'expected')})
            (root / 'unexpected.php').write_bytes(b'extra')
            with self.assertRaises(ValueError):
                verify(root, {})

if __name__ == '__main__':
    unittest.main()
