#!/usr/bin/env python3
"""BMS Release Operator V1a: independently verify an immutable CI ZIP and manifest.

This process is entirely local and read-only. A verified archive remains HOLD:
no server inventory, authenticated CI provenance, approval or deployment occurs.
"""
from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path
import re
import stat
import zipfile

from preflight import validate, InvalidRequest

MAX_MANIFEST_BYTES = 16 * 1024 * 1024
MAX_ZIP_BYTES = 128 * 1024 * 1024
MAX_ENTRIES = 10000
MAX_FILE_BYTES = 64 * 1024 * 1024
MAX_EXPANDED_BYTES = 512 * 1024 * 1024
ZIP_TIMESTAMP = (1980, 1, 1, 0, 0, 0)
SAFE_SHA = re.compile(r"[a-f0-9]{40}\Z")
SAFE_DECIMAL = re.compile(r"[0-9]{1,16}\Z")
VERSION = re.compile(rb"\$plugin->version\s*=\s*(\d+);")


class ArtifactHold(ValueError):
    pass


def must(ok: bool, reason: str) -> None:
    if not ok:
        raise ArtifactHold(reason)


def strict_object(pairs):
    d = {}
    for key, value in pairs:
        if key in d:
            raise ArtifactHold("duplicate JSON key in evidence")
        d[key] = value
    return d


def read_json(path: Path, limit: int) -> dict:
    must(path.is_file() and not path.is_symlink(), "evidence file missing or symlink")
    must(path.stat().st_size <= limit, "evidence file too large")
    try:
        data = json.loads(path.read_text(encoding="utf-8"), object_pairs_hook=strict_object)
    except (ValueError, UnicodeError) as exc:
        raise ArtifactHold("invalid JSON evidence") from exc
    must(isinstance(data, dict), "evidence must be JSON object")
    return data


def sha_file(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as fh:
        for chunk in iter(lambda: fh.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def safe_name(name: object) -> bool:
    if not isinstance(name, str) or not name.startswith("kbacompany/"):
        return False
    if "\\" in name or ":" in name or "\x00" in name or name.endswith("/"):
        return False
    parts = name.split("/")
    if any(part in ("", ".", "..") for part in parts):
        return False
    if any(part in (".git", ".idea", "__pycache__") for part in parts):
        return False
    forbidden = {"config.php", ".env", "id_rsa", "id_ed25519"}
    if any(part in forbidden or part.startswith(".env.") for part in parts):
        return False
    if name.lower().endswith((".pem", ".key", ".p12", ".pfx", ".zip")):
        return False
    return True


def sha40(value: object) -> bool:
    return isinstance(value, str) and SAFE_SHA.fullmatch(value) is not None


def digits(value: object) -> bool:
    return isinstance(value, str) and SAFE_DECIMAL.fullmatch(value) is not None


def verify(request_path: Path, archive_path: Path, manifest_path: Path,
           base_sha: str, run_id: str, run_attempt: str) -> dict:
    must(sha40(base_sha), "invalid external base SHA")
    must(digits(run_id) and digits(run_attempt), "invalid expected workflow run identity")
    try:
        request = validate(read_json(request_path, MAX_MANIFEST_BYTES))
    except InvalidRequest as exc:
        raise ArtifactHold("invalid V0 release request: " + str(exc)) from exc
    candidate = request["candidate"]
    must(archive_path.is_file() and not archive_path.is_symlink(), "archive unavailable or symlink")
    must(archive_path.stat().st_size <= MAX_ZIP_BYTES, "archive exceeds size bound")
    must(sha_file(archive_path) == candidate["zip_sha256"], "archive SHA256 differs from pinned request")
    must(manifest_path.is_file() and not manifest_path.is_symlink(), "manifest unavailable or symlink")
    must(manifest_path.stat().st_size <= MAX_MANIFEST_BYTES, "manifest exceeds size bound")
    must(sha_file(manifest_path) == candidate["manifest_sha256"],
         "manifest SHA256 differs from pinned request")
    manifest = read_json(manifest_path, MAX_MANIFEST_BYTES)
    artifact = manifest.get("artifact")
    source = manifest.get("source")
    components = manifest.get("components")
    entries = manifest.get("entries")
    must(isinstance(artifact, dict) and isinstance(source, dict) and
         isinstance(components, dict) and isinstance(entries, list), "incomplete CI manifest")
    must(artifact.get("component") == "local_kbacompany", "wrong plugin component")
    must(type(artifact.get("version")) is int and
         artifact["version"] == candidate["plugin_version"], "version mismatch")
    must(artifact.get("zip_name") == "candidate-install.zip" and
         archive_path.name == "candidate-install.zip", "unexpected archive name")
    must(artifact.get("zip_sha256") == candidate["zip_sha256"], "manifest archive hash mismatch")
    must(type(artifact.get("files")) is int and artifact["files"] == len(entries) and
         0 < len(entries) <= MAX_ENTRIES, "manifest entry count mismatch")
    must(source.get("pr_head") == candidate["head_sha"], "candidate head mismatch")
    must(source.get("checkout_sha") == candidate["ci_checkout_sha"], "CI checkout mismatch")
    must(source.get("base") == base_sha, "candidate base mismatch")
    must(sha40(source.get("tree")), "CI checkout tree missing")
    must(str(source.get("run_id")) == run_id and
         str(source.get("run_attempt")) == run_attempt, "CI workflow run mismatch")
    bms = components.get("local/kbacompany")
    must(isinstance(bms, dict) and type(bms.get("version")) is int and
         bms["version"] == candidate["plugin_version"], "manifest component version mismatch")
    must(all(isinstance(item, dict) and set(item) == {"path", "sha256", "bytes"} for item in entries),
         "unexpected manifest entries")
    names = []
    expected = {}
    total = 0
    for entry in entries:
        name = entry["path"]
        must(safe_name(name), "unsafe archive entry path")
        must(name not in expected, "duplicate manifest entry")
        must(isinstance(entry["sha256"], str) and
             re.fullmatch(r"[a-f0-9]{64}", entry["sha256"]) is not None, "invalid entry hash")
        must(type(entry["bytes"]) is int and 0 <= entry["bytes"] <= MAX_FILE_BYTES,
             "entry size exceeds limit")
        names.append(name)
        expected[name] = entry
        total += entry["bytes"]
        must(total <= MAX_EXPANDED_BYTES, "expanded archive exceeds limit")
    must(names == sorted(names), "manifest entries are not canonical")
    must("kbacompany/version.php" in expected, "missing plugin version.php")
    try:
        with zipfile.ZipFile(archive_path, "r") as zf:
            infos = zf.infolist()
            must(len(infos) == len(names) and [i.filename for i in infos] == names,
                 "archive entry set/order differs from pinned manifest")
            plugin_version = None
            for info in infos:
                must(safe_name(info.filename), "unsafe ZIP entry path")
                must(info.file_size == expected[info.filename]["bytes"] and
                     info.file_size <= MAX_FILE_BYTES, "archive entry byte length mismatch")
                must(info.date_time == ZIP_TIMESTAMP and info.compress_type == zipfile.ZIP_DEFLATED,
                     "archive not in deterministic package format")
                must(info.create_system == 3 and stat.S_IFMT(info.external_attr >> 16) == stat.S_IFREG and
                     stat.S_IMODE(info.external_attr >> 16) == 0o644, "archive has unsafe file mode")
                must(not info.flag_bits & 1, "encrypted archive entry prohibited")
                digest = hashlib.sha256()
                actual = 0
                version_bytes = bytearray()
                with zf.open(info, "r") as stream:
                    while True:
                        chunk = stream.read(1024 * 1024)
                        if not chunk:
                            break
                        actual += len(chunk)
                        must(actual <= info.file_size and actual <= MAX_FILE_BYTES,
                             "expanded file exceeded declared size")
                        digest.update(chunk)
                        if info.filename == "kbacompany/version.php":
                            must(len(version_bytes) + len(chunk) <= 64 * 1024,
                                 "plugin version file unexpectedly large")
                            version_bytes.extend(chunk)
                must(actual == info.file_size and
                     digest.hexdigest() == expected[info.filename]["sha256"],
                     "file content does not match pinned manifest")
                if info.filename == "kbacompany/version.php":
                    m = VERSION.search(version_bytes)
                    must(m is not None, "plugin version marker missing")
                    plugin_version = int(m.group(1))
            must(plugin_version == candidate["plugin_version"], "plugin runtime version mismatch")
    except (zipfile.BadZipFile, RuntimeError, EOFError) as exc:
        raise ArtifactHold("damaged or unreadable ZIP") from exc
    return {
        "schema": "bms24.release_operator.artifact_report.v1a",
        "status": "HOLD",
        "deployable": False,
        "archive_bytes_verified": True,
        "manifest_bytes_verified": True,
        "file_entries_verified": len(entries),
        "candidate_sha": candidate["head_sha"],
        "ci_checkout_sha": candidate["ci_checkout_sha"],
        "ci_base_sha": base_sha,
        "ci_run_id": run_id,
        "ci_run_attempt": run_attempt,
        "server_baseline_verified": False,
        "ci_status_authenticated": False,
        "owner_approval_verified": False,
        "actions_executed": [],
        "blockers": [
            "CI provider and check-run provenance not independently authenticated by this offline verifier",
            "No live production inventory or independently verified backup/restore",
            "No tested protected migration executor and no owner deployment approval",
        ],
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--request", type=Path, required=True)
    parser.add_argument("--zip", type=Path, required=True)
    parser.add_argument("--manifest", type=Path, required=True)
    parser.add_argument("--base-sha", required=True)
    parser.add_argument("--run-id", required=True)
    parser.add_argument("--run-attempt", required=True)
    parser.add_argument("--report", type=Path, required=True)
    args = parser.parse_args()
    try:
        report = verify(args.request, args.zip, args.manifest,
                        args.base_sha, args.run_id, args.run_attempt)
        exitcode = 0
    except (ArtifactHold, OSError, TypeError, KeyError) as exc:
        report = {"schema": "bms24.release_operator.artifact_report.v1a",
                  "status": "REJECTED", "deployable": False,
                  "reason": str(exc)[:200], "actions_executed": []}
        exitcode = 2
    args.report.parent.mkdir(parents=True, exist_ok=True)
    args.report.write_text(json.dumps(report, indent=2, ensure_ascii=False) + "\n",
                           encoding="utf-8")
    print("BMS24 artifact gate: " + report["status"] + " (NO DEPLOYMENT)")
    return exitcode


if __name__ == "__main__":
    raise SystemExit(main())
