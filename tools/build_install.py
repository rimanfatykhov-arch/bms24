#!/usr/bin/env python3
"""Deterministic, read-only packager for the full BMS24 Moodle plugin.

Stable output across machines and builds for the *same source bytes*:
canonical path ordering, fixed ZIP timestamps, normalized modes and encoding.
"""
from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path
import re
import sys
import zipfile

ROOT = Path(__file__).resolve().parents[1]
EXCLUDE_DIRS = {".git", ".idea", "__pycache__"}
EXCLUDE_FILES = {".DS_Store", "Thumbs.db"}
FORBIDDEN_FILES = {"config.php", ".env", "id_rsa", "id_ed25519"}
ZIP_DATE = (1980, 1, 1, 0, 0, 0)


def sha256(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def collect_files(source: Path):
    if not (source / "version.php").is_file():
        raise ValueError("source is not a Moodle plugin (version.php missing)")
    version = (source / "version.php").read_text(encoding="utf-8")
    m = re.search(r"\$plugin->version\s*=\s*(\d+);", version)
    if not m:
        raise ValueError("plugin version unavailable")
    files = []
    for file in source.rglob("*"):
        rel = file.relative_to(source)
        if file.is_symlink():
            raise ValueError("symbolic link inside source is prohibited")
        if not file.is_file():
            continue
        if any(part in EXCLUDE_DIRS for part in rel.parts):
            continue
        if file.name in FORBIDDEN_FILES or file.name.startswith(".env."):
            raise ValueError("sensitive filename detected in source")
        if file.name in EXCLUDE_FILES or file.suffix.lower() in {".pyc", ".zip"}:
            continue
        if ".tmp." in file.name:
            continue
        files.append((f"kbacompany/{rel.as_posix()}", file))
    files.sort(key=lambda x: x[0])
    if len({x[0] for x in files}) != len(files):
        raise ValueError("duplicate ZIP entry")
    return int(m.group(1)), files


def build(source: Path, output: Path, manifest: Path | None = None) -> dict:
    source = source.resolve(strict=True)
    output = output.resolve()
    if output.is_relative_to(source):
        raise ValueError("output must be outside plugin source")
    if manifest is not None and manifest.resolve().is_relative_to(source):
        raise ValueError("manifest must be outside plugin source")
    version, files = collect_files(source)
    output.parent.mkdir(parents=True, exist_ok=True)
    contents = []
    tmp = output.with_suffix(output.suffix + ".tmp")
    try:
        with zipfile.ZipFile(tmp, "w", zipfile.ZIP_DEFLATED, compresslevel=6, strict_timestamps=True) as zf:
            for arc, path in files:
                data = path.read_bytes()
                info = zipfile.ZipInfo(arc, date_time=ZIP_DATE)
                info.create_system = 3
                info.external_attr = (0o100644 << 16)
                info.compress_type = zipfile.ZIP_DEFLATED
                zf.writestr(info, data, compress_type=zipfile.ZIP_DEFLATED, compresslevel=6)
                contents.append({"path": arc, "sha256": sha256(data), "bytes": len(data)})
        with zipfile.ZipFile(tmp) as zf:
            if zf.testzip() is not None or len(zf.namelist()) != len(contents):
                raise ValueError("ZIP integrity failed")
            if not zf.namelist() or zf.namelist()[0] != sorted(zf.namelist())[0]:
                raise ValueError("ZIP sorting failed")
            if "kbacompany/version.php" not in zf.namelist():
                raise ValueError("ZIP root structure invalid")
        tmp.replace(output)
    finally:
        if tmp.exists():
            tmp.unlink()
    result = {"component": "local_kbacompany", "version": version, "files": len(contents),
              "zip_sha256": sha256(output.read_bytes()), "zip_name": output.name}
    if manifest is not None:
        manifest.parent.mkdir(parents=True, exist_ok=True)
        manifest.write_text(json.dumps({"artifact": result, "entries": contents}, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    return result


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--source", type=Path, default=ROOT / "local/kbacompany")
    parser.add_argument("--output", required=True, type=Path)
    parser.add_argument("--manifest", type=Path)
    args = parser.parse_args()
    try:
        result = build(args.source, args.output, args.manifest)
        print(json.dumps({"status": "READY", **result}, ensure_ascii=False))
    except (OSError, ValueError, RuntimeError, zipfile.BadZipFile) as e:
        print(json.dumps({"status": "HOLD", "reason": str(e)}, ensure_ascii=False), file=sys.stderr)
        sys.exit(2)


if __name__ == "__main__":
    main()
