"""Read-only binding of a module preview to one reviewed ZIP and manifest. Not a deployment gate."""
import argparse
import hashlib
import json
import stat
import zipfile
from pathlib import Path, PurePosixPath


def digest(data):
    return hashlib.sha256(data).hexdigest()


def verify(archive, manifest, expected_zip, expected_manifest):
    archive, manifest = Path(archive), Path(manifest)
    if digest(archive.read_bytes()) != expected_zip or digest(manifest.read_bytes()) != expected_manifest:
        raise ValueError('reviewed package checksum mismatch')
    m = json.loads(manifest.read_text(encoding='utf-8'))
    if m['artifact']['component'] != 'local_kbacompany' or m['artifact']['zip_sha256'] != expected_zip:
        raise ValueError('unexpected component or manifest binding')
    entries = m['entries']
    expected = {e['path']: e for e in entries}
    if len(expected) != len(entries) or len(entries) != m['artifact']['files']:
        raise ValueError('duplicate or missing manifest entries')
    with zipfile.ZipFile(archive) as z:
        names = z.namelist()
        if len(set(names)) != len(names) or set(names) != set(expected):
            raise ValueError('ZIP inventory differs from manifest')
        if 'kbacompany/version.php' not in names:
            raise ValueError('missing module version')
        for info in z.infolist():
            p = PurePosixPath(info.filename)
            if (p.is_absolute() or '..' in p.parts or '\\' in info.filename or p.parts[0] != 'kbacompany'
                    or p.as_posix() != info.filename or any(':' in part for part in p.parts)):
                raise ValueError('unsafe or foreign component path')
            if info.is_dir() or stat.S_ISLNK(info.external_attr >> 16) or info.file_size > 128 * 1024**2:
                raise ValueError('unsupported entry')
            if p.name in ('config.php', '.env', 'id_ed25519', 'id_rsa') or p.name.startswith('.env.'):
                raise ValueError('sensitive filename')
            entry = expected[info.filename]
            data = z.read(info)  # Includes ZIP CRC verification.
            if len(data) != entry['bytes'] or digest(data) != entry['sha256']:
                raise ValueError('entry checksum mismatch')
    return {'status': 'PASS', 'scope': 'package identity only; no CI/runtime/backup/deploy approval',
            'zip_sha256': expected_zip, 'manifest_sha256': expected_manifest, 'files': len(entries)}


if __name__ == '__main__':
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('zip'); p.add_argument('manifest')
    p.add_argument('--zip-sha256', required=True); p.add_argument('--manifest-sha256', required=True)
    a = p.parse_args()
    print(json.dumps(verify(a.zip, a.manifest, a.zip_sha256, a.manifest_sha256)))
