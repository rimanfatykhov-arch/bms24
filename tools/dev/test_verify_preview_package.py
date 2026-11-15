import json
import tempfile
import unittest
import zipfile
from pathlib import Path
from verify_preview_package import verify, digest


class BindingTest(unittest.TestCase):
    def fixture(self, names=None):
        temp = tempfile.TemporaryDirectory(); self.addCleanup(temp.cleanup)
        root = Path(temp.name); z = root/'module.zip'; m = root/'manifest.json'
        names = names or ['kbacompany/version.php', 'kbacompany/styles.css']
        with zipfile.ZipFile(z, 'w') as archive:
            for n in names: archive.writestr(n, b'test fixture')
        entries = [{'path': n, 'bytes': 12, 'sha256': digest(b'test fixture')} for n in names]
        body = {'artifact': {'component': 'local_kbacompany', 'files': len(entries),
                            'zip_sha256': digest(z.read_bytes())}, 'entries': entries}
        m.write_text(json.dumps(body)); return z, m, body

    def runcheck(self, z, m):
        return verify(z, m, digest(z.read_bytes()), digest(m.read_bytes()))

    def test_exact_package(self):
        z, m, _ = self.fixture(); self.assertEqual('PASS', self.runcheck(z, m)['status'])

    def test_changed_zip(self):
        z, m, _ = self.fixture()
        with self.assertRaises(ValueError): verify(z, m, '0'*64, digest(m.read_bytes()))

    def test_changed_manifest(self):
        z, m, _ = self.fixture()
        with self.assertRaises(ValueError): verify(z, m, digest(z.read_bytes()), '0'*64)

    def test_missing_entry(self):
        z, m, body = self.fixture(); body['entries'].pop(); body['artifact']['files'] = 1
        m.write_text(json.dumps(body))
        with self.assertRaisesRegex(ValueError, 'inventory'): self.runcheck(z, m)

    def test_entry_bytes_changed(self):
        z, m, body = self.fixture(); body['entries'][0]['sha256'] = '0'*64; m.write_text(json.dumps(body))
        with self.assertRaisesRegex(ValueError, 'checksum'): self.runcheck(z, m)

    def test_foreign_component(self):
        z, m, _ = self.fixture(['kbacompany/version.php', 'kbacorporate/lib.php'])
        with self.assertRaisesRegex(ValueError, 'foreign'): self.runcheck(z, m)

    def test_parent_escape(self):
        z, m, _ = self.fixture(['kbacompany/version.php', 'kbacompany/../config.php'])
        with self.assertRaisesRegex(ValueError, 'unsafe'): self.runcheck(z, m)

    def test_secret_filename(self):
        z, m, _ = self.fixture(['kbacompany/version.php', 'kbacompany/config.php'])
        with self.assertRaisesRegex(ValueError, 'sensitive'): self.runcheck(z, m)


if __name__ == '__main__': unittest.main()
