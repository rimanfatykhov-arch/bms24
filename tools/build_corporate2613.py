#!/usr/bin/env python3
"""Materialize the reviewed2613 patch in a NEW local directory; never deploy."""
import argparse
import hashlib
import io
import json
from pathlib import Path, PurePosixPath
import subprocess
import tarfile
import zipfile

ROOT = Path(__file__).resolve().parents[1]
SOURCE = '687c230595bce020fc001db91b3aac276f0fee7d'

def digest(data):
    return hashlib.sha256(data).hexdigest()

def inventory(root):
    files = {}
    for path in root.rglob('*'):
        if path.is_symlink():
            raise ValueError('Symlinks are not accepted')
        if path.is_file():
            files[path.relative_to(root).as_posix()] = digest(path.read_bytes())
    return dict(sorted(files.items()))

def verify(root, expected):
    actual = inventory(root)
    if actual != expected:
        changed = sorted(p for p in actual.keys() | expected.keys() if actual.get(p) != expected.get(p))
        raise ValueError('Source manifest mismatch: ' + ', '.join(changed))
    return digest(json.dumps(actual, sort_keys=True, separators=(',', ':')).encode())

def build(output):
    before = json.loads((ROOT / 'docs/release/corporate2613-manifest.json').read_text())['files']
    manifest = json.loads((ROOT / 'release/corporate2613/manifest.json').read_text())
    patch = ROOT / 'release/corporate2613/compatibility.patch'
    if manifest['base_sha'] != SOURCE or digest(patch.read_bytes()) != manifest['patch_sha256']:
        raise ValueError('Unreviewed base or patch')
    if set(before) != set(manifest['files']) or len(before) != 830:
        raise ValueError('Unexpected added/deleted source files')
    changed = sorted(p for p in before if before[p] != manifest['files'][p])
    if changed != manifest['changed_paths']:
        raise ValueError('Changed-path inventory mismatch')
    # Refuse reuse so this cannot overwrite an installed plugin or existing work.
    output.mkdir(parents=True, exist_ok=False)
    raw = subprocess.check_output(['git', '-c', 'core.autocrlf=false', '-c', 'core.eol=lf',
        '-C', str(ROOT), 'archive', SOURCE, 'local/kbacorporate'])
    with tarfile.open(fileobj=io.BytesIO(raw)) as archive:
        for member in archive:
            if member.isdir():
                continue
            path = PurePosixPath(member.name)
            if not member.isfile() or path.is_absolute() or '..' in path.parts or path.parts[:2] != ('local', 'kbacorporate'):
                raise ValueError('Unsafe source archive')
            target = output.joinpath(*path.parts)
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(archive.extractfile(member).read())
    plugin = output / 'local/kbacorporate'
    verify(plugin, before)
    # Git applies exact context (no fuzz/3way/whitespace repair). The complete
    # post-image manifest additionally rejects offsets or any unexpected result.
    for flags in (['--check'], []):
        subprocess.run(['git', '-c', 'core.autocrlf=false', '-c', 'core.eol=lf',
            'apply', *flags, str(patch)], cwd=output, check=True)
    tree = verify(plugin, manifest['files'])
    zipname = 'INSTALL_local_kbacorporate_0.40.13-bms.1_build2026111003.zip'
    # Stored entries avoid platform/zlib-dependent compressed bytes. Actions may
    # compress the outer evidence download; the reviewed install ZIP stays exact.
    with zipfile.ZipFile(output / zipname, 'w', zipfile.ZIP_STORED) as archive:
        for path in sorted(manifest['files']):
            info = zipfile.ZipInfo('kbacorporate/' + path, date_time=(2026, 11, 10, 0, 0, 0))
            # Keep ZIP metadata identical on Windows authoring and Linux CI.
            info.create_system = 3
            info.compress_type = zipfile.ZIP_STORED
            info.external_attr = 0o100644 << 16
            archive.writestr(info, (plugin / path).read_bytes())
    identity = {'base_sha': SOURCE, 'version': manifest['version'], 'patch_sha256': manifest['patch_sha256'],
        'file_count': len(manifest['files']), 'tree_sha256': tree, 'zip': zipname,
        'zip_sha256': digest((output / zipname).read_bytes()), 'deployment': 'HOLD'}
    (output / 'IDENTITY.json').write_text(json.dumps(identity, indent=2) + '\n')
    print(json.dumps(identity))

if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path)
    parser.add_argument('--verify', type=Path)
    args = parser.parse_args()
    if bool(args.output) == bool(args.verify):
        parser.error('Exactly one of --output or --verify is required')
    if args.verify:
        manifest = json.loads((ROOT / 'release/corporate2613/manifest.json').read_text())
        print(verify(args.verify, manifest['files']))
    else:
        build(args.output.resolve())
