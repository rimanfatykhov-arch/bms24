"""Negative acceptance for the independent, offline BMS24 artifact gate."""
from __future__ import annotations

import hashlib
import json
from pathlib import Path
import sys
import tempfile
import unittest
import warnings
import zipfile

sys.path.insert(0, str(Path(__file__).resolve().parent))
from artifact_gate import (ArtifactHold, MAX_FILE_BYTES, ZIP_TIMESTAMP, read_json,
                           verify)

HEAD = "a" * 40
CHECKOUT = "b" * 40
BASE = "c" * 40
RUN_ID = "44237304437"


def digest(content: bytes) -> str:
    return hashlib.sha256(content).hexdigest()


class ArtifactGateTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.dir = Path(self.tmp.name)
        self.archive = self.dir / "candidate-install.zip"
        self.manifest_file = self.dir / "manifest.json"
        self.request_file = self.dir / "request.json"
        self.files = {
            "kbacompany/version.php": b"<?php\n$plugin->version = 2026111601;\n",
            "kbacompany/amd/src/hello.js": b"console.log('hello');\n",
        }
        self.modes = {}
        self.compression = zipfile.ZIP_DEFLATED
        self.refresh()

    def refresh(self):
        entries = []
        with zipfile.ZipFile(self.archive, "w") as zf:
            for path, data in sorted(self.files.items()):
                info = zipfile.ZipInfo(path, date_time=ZIP_TIMESTAMP)
                info.create_system = 3
                info.external_attr = self.modes.get(path, 0o100644) << 16
                info.compress_type = self.compression
                zf.writestr(info, data)
                entries.append({"path": path, "sha256": digest(data), "bytes": len(data)})
        self.manifest = {
            "artifact": {"component": "local_kbacompany", "version": 2026111601,
                         "files": len(entries), "zip_sha256": digest(self.archive.read_bytes()),
                         "zip_name": "candidate-install.zip"},
            "entries": entries,
            "source": {"pr_head": HEAD, "checkout_sha": CHECKOUT, "base": BASE,
                       "tree": "d" * 40, "run_id": RUN_ID, "run_attempt": "1"},
            "components": {"local/kbacompany": {"version": 2026111601},
                           "local/kbacorporate": {"version": 2026111100}},
        }
        self.request = {
            "schema": "bms24.release_operator.request.v0",
            "release_id": "BMS24-R1R2-20261119",
            "repository": "rimanfatykhov-arch/bms24",
            "environment": "production",
            "mode": "read_only_preflight",
            "production_baseline": {"source_sha": "9" * 40,
                                     "plugin_version": 2026111100},
            "candidate": {"pr_number": 124, "head_sha": HEAD,
                          "ci_checkout_sha": CHECKOUT, "plugin_version": 2026111601,
                          "zip_sha256": self.manifest["artifact"]["zip_sha256"],
                          "manifest_sha256": ""},
            "migration": {"required": True, "expected_new_tables": 5,
                          "recovery": "feature_off_forward_fix_or_separately_approved_restore"},
            "authorization": {"owner_approval": "NOT_GRANTED", "deployment_window": None},
        }
        self.seal_manifest()

    def seal_manifest(self):
        self.manifest_file.write_text(json.dumps(self.manifest), encoding="utf-8")
        self.request["candidate"]["manifest_sha256"] = digest(self.manifest_file.read_bytes())
        self.seal_request()

    def seal_request(self):
        self.request_file.write_text(json.dumps(self.request), encoding="utf-8")

    def check(self, base=BASE, run=RUN_ID, attempt="1"):
        return verify(self.request_file, self.archive, self.manifest_file,
                      base, run, attempt)

    def reject(self):
        with self.assertRaises(ArtifactHold):
            self.check()

    def test_valid_archive_holds_but_verifies_exact_bytes(self):
        report = self.check()
        self.assertEqual(report["status"], "HOLD")
        self.assertTrue(report["archive_bytes_verified"])
        self.assertTrue(report["manifest_bytes_verified"])
        self.assertEqual(report["file_entries_verified"], 2)
        self.assertFalse(report["deployable"])
        self.assertFalse(report["ci_status_authenticated"])
        self.assertEqual(report["actions_executed"], [])

    def test_modified_zip_or_manifest_rejected(self):
        self.archive.write_bytes(self.archive.read_bytes() + b"tail")
        self.reject()
        self.refresh()
        self.manifest_file.write_text(self.manifest_file.read_text() + "\n")
        self.reject()

    def test_manifest_component_or_entry_count_rejected_even_with_updated_pin(self):
        self.manifest["artifact"]["component"] = "local_kbacorporate"
        self.seal_manifest()
        self.reject()
        self.refresh()
        self.manifest["artifact"]["files"] = 3
        self.seal_manifest()
        self.reject()

    def test_wrong_ci_head_checkout_base_and_run_rejected(self):
        for key, bad in [("pr_head", "0" * 40), ("checkout_sha", "1" * 40),
                         ("base", "2" * 40), ("run_id", "123456"),
                         ("run_attempt", "2")]:
            with self.subTest(key=key):
                self.refresh()
                self.manifest["source"][key] = bad
                self.seal_manifest()
                self.reject()
        self.refresh()
        with self.assertRaises(ArtifactHold):
            self.check(base="f" * 40)
        with self.assertRaises(ArtifactHold):
            self.check(run="42")
        with self.assertRaises(ArtifactHold):
            self.check(attempt="2")

    def test_wrong_plugin_version_inside_zip_rejected(self):
        self.files["kbacompany/version.php"] = b"<?php\n$plugin->version = 2026111700;"
        self.refresh()
        self.reject()

    def test_zip_path_traversal_and_foreign_plugin_rejected(self):
        for unsafe in ("kbacompany/../config.php", "kbacompany/x/./file.txt",
                       "kbacompany\\file.txt", "local/kbacorporate/version.php",
                       "kbacompany/.env", "kbacompany/secret.key"):
            with self.subTest(path=unsafe):
                self.files = {"kbacompany/version.php": b"$plugin->version = 2026111601;",
                              unsafe: b"bad"}
                self.refresh()
                self.reject()

    def test_zip_symlink_file_mode_rejected(self):
        self.modes["kbacompany/amd/src/hello.js"] = 0o120777
        self.refresh()
        self.reject()

    def test_zip_stored_instead_of_canonical_deflate_rejected(self):
        self.compression = zipfile.ZIP_STORED
        self.refresh()
        self.reject()

    def test_manifest_duplicate_entries_and_inconsistent_hash_rejected(self):
        self.manifest["entries"].append(self.manifest["entries"][0])
        self.manifest["artifact"]["files"] += 1
        self.seal_manifest()
        self.reject()
        self.refresh()
        self.manifest["entries"][0]["sha256"] = "e" * 64
        self.seal_manifest()
        self.reject()

    def test_modified_entry_bytes_or_size_rejected(self):
        self.manifest["entries"][0]["bytes"] += 1
        self.seal_manifest()
        self.reject()
        self.refresh()
        self.manifest["entries"][0]["bytes"] = MAX_FILE_BYTES + 1
        self.seal_manifest()
        self.reject()

    def test_manifest_duplicate_json_key_rejected_even_with_updated_hash(self):
        raw = b'{"artifact":{},"artifact":{}}'
        self.manifest_file.write_bytes(raw)
        self.request["candidate"]["manifest_sha256"] = digest(raw)
        self.seal_request()
        self.reject()
        with self.assertRaises(ArtifactHold):
            read_json(self.manifest_file, 200)

    def test_bad_request_and_attempt_to_approve_deployment_rejected(self):
        for k, val in [("mode", "deploy"), ("environment", "live"),
                       ("repository", "other/repo")]:
            with self.subTest(k=k):
                self.refresh()
                self.request[k] = val
                self.seal_request()
                self.reject()
        self.refresh()
        self.request["authorization"]["owner_approval"] = "GRANTED"
        self.seal_request()
        self.reject()

    def test_tampered_zip_name_or_extraneous_entry_rejected(self):
        self.manifest["artifact"]["zip_name"] = "other.zip"
        self.seal_manifest()
        self.reject()
        self.refresh()
        # ZIP-only entry, while preserving all pinned metadata of the manifest
        with warnings.catch_warnings():
            warnings.simplefilter("ignore")
            with zipfile.ZipFile(self.archive, "a") as zf:
                zf.writestr("kbacompany/unlisted.txt", b"unlisted")
        self.request["candidate"]["zip_sha256"] = digest(self.archive.read_bytes())
        self.manifest["artifact"]["zip_sha256"] = self.request["candidate"]["zip_sha256"]
        self.seal_manifest()
        self.reject()

    def test_manifest_is_symlink_rejected(self):
        other = self.dir / "other.json"
        other.write_bytes(self.manifest_file.read_bytes())
        self.manifest_file.unlink()
        self.manifest_file.symlink_to(other)
        self.reject()

    def test_pinned_hex_inputs_are_restricted(self):
        with self.assertRaises(ArtifactHold):
            self.check(base="not-a-sha")
        with self.assertRaises(ArtifactHold):
            self.check(run="1; rm -rf /")
        with self.assertRaises(ArtifactHold):
            self.check(attempt="-1")


if __name__ == "__main__":
    unittest.main()
