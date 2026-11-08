#!/usr/bin/env python3
"""Offline fail-closed verifier for a GitHub BMS24 candidate ZIP.

Does not need Moodle, database, VDS access or credentials. Checks integrity,
required layout and optional per-file SHA-256 manifest from build_install.py.
"""
from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path
import re
import stat
import sys
import zipfile

MAX_MEMBER = 32 * 1024 * 1024  # Code file limit; block ZIP bombs in CI.
MAX_TOTAL = 160 * 1024 * 1024
MAX_FILES = 20000


def sha(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def verify(archive: Path, manifest: Path | None = None, expected: int | None = None) -> dict:
    if not archive.is_file():
        raise ValueError('missing ZIP')
    entries = {}
    total = 0
    with zipfile.ZipFile(archive) as zf:
        infos = zf.infolist()
        if not infos or len(infos) > MAX_FILES:
            raise ValueError('empty or oversized ZIP file list')
        names = [x.filename for x in infos]
        if len(set(names)) != len(names) or names != sorted(names):
            raise ValueError('duplicate or unsorted ZIP entries')
        if 'kbacompany/version.php' not in names:
            raise ValueError('missing plugin version.php')
        for info in infos:
            path = info.filename
            name = path.rsplit('/', 1)[-1]
            if (not path.startswith('kbacompany/') or not name or '\\' in path
                    or any(p in ('', '.', '..') for p in path.split('/'))
                    or path.endswith(('.py', '.pyc', '.pyo', '.zip'))
                    or name in {'.env', 'config.php', 'id_rsa', 'id_ed25519'}
                    or name.startswith('.env.') or 'moodledata' in path.split('/')):
                raise ValueError('unsafe or development-only ZIP member')
            mode = (info.external_attr >> 16)
            if stat.S_ISLNK(mode) or info.flag_bits & 1:
                raise ValueError('symlink or encrypted ZIP member')
            if info.file_size > MAX_MEMBER or total + info.file_size > MAX_TOTAL:
                raise ValueError('ZIP member or total uncompressed size exceeds limit')
            total += info.file_size
            data = zf.read(info)
            entries[path] = {'path': path, 'sha256': sha(data), 'bytes': len(data)}
        version_bytes = zf.read('kbacompany/version.php')
        match = re.search(rb'\$plugin->version\s*=\s*(\d+)\s*;', version_bytes)
        if not match:
            raise ValueError('invalid plugin version.php')
        version = int(match.group(1))
        if expected is not None and version != expected:
            raise ValueError('unexpected plugin version')
    if manifest is not None:
        doc = json.loads(manifest.read_text(encoding='utf-8'))
        if not isinstance(doc, dict) or not isinstance(doc.get('entries'), list):
            raise ValueError('invalid manifest')
        rows = doc['entries']
        if rows != list(entries.values()):
            raise ValueError('manifest contents do not match ZIP files or hashes')
        artifact = doc.get('artifact') or {}
        if (artifact.get('version') != version or artifact.get('files') != len(entries)
                or artifact.get('zip_sha256') != sha(archive.read_bytes())):
            raise ValueError('manifest metadata does not match archive')
    return {'status': 'PASS', 'version': version, 'files': len(entries),
            'bytes_uncompressed': total, 'sha256': sha(archive.read_bytes()),
            'manifest_checked': manifest is not None}


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument('--zip', dest='archive', type=Path, required=True)
    ap.add_argument('--manifest', type=Path)
    ap.add_argument('--expected-version', type=int)
    args = ap.parse_args()
    try:
        print(json.dumps(verify(args.archive, args.manifest, args.expected_version)))
        return 0
    except (ValueError, OSError, json.JSONDecodeError, zipfile.BadZipFile, RuntimeError) as exc:
        # Do not leak local paths or possibly sensitive exception messages.
        print(json.dumps({'status': 'HOLD', 'reason': type(exc).__name__}), file=sys.stderr)
        return 2


if __name__ == '__main__':
    raise SystemExit(main())
