#!/usr/bin/env python3
"""Artifact provenance regression: no GitHub import push without verified ZIP."""
from pathlib import Path
import hashlib
import sys
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parent))
import github_import as gi
from test_github_import_1877 import GitHubImportTest, run

class VerifiedSourceTest(GitHubImportTest):
    def test_dry_run_can_verify_exact_candidate(self):
        out = gi.run(self.bundle, str(self.remote), gi.DEFAULT_BRANCH,
                     install_zip=self.install, approved_sha256=self.approved_sha)
        self.assertTrue(out["candidate_verified"])
        self.assertEqual(out["zip_sha256"],self.approved_sha)
        self.assertFalse(out["pushed"])
        self.assertEqual(run("-C",self.remote,"rev-parse","refs/heads/main"),self.mainsha)

    def test_push_without_candidate_is_rejected_before_remote_access(self):
        with self.assertRaisesRegex(gi.ImportHold,"requires a reviewed INSTALL ZIP"):
            gi.run(self.bundle,str(self.remote),gi.DEFAULT_BRANCH,push=True)

    def test_missing_expected_sha_is_rejected(self):
        with self.assertRaisesRegex(gi.ImportHold,"must be supplied together"):
            gi.run(self.bundle,str(self.remote),gi.DEFAULT_BRANCH,install_zip=self.install)

    def test_bad_digest_is_rejected(self):
        with self.assertRaisesRegex(gi.ImportHold,"does not match approved"):
            gi.run(self.bundle,str(self.remote),gi.DEFAULT_BRANCH,
                   install_zip=self.install,approved_sha256="0"*64)

    def test_invalid_digest_is_rejected(self):
        with self.assertRaisesRegex(gi.ImportHold,"64 hex"):
            gi.run(self.bundle,str(self.remote),gi.DEFAULT_BRANCH,
                   install_zip=self.install,approved_sha256="bad")

    def test_damaged_zip_fails_without_push(self):
        self.install.write_bytes(b"garbage")
        with self.assertRaisesRegex(gi.ImportHold,"invalid"):
            gi.run(self.bundle,str(self.remote),gi.DEFAULT_BRANCH,push=True,
                   install_zip=self.install, approved_sha256=hashlib.sha256(b"garbage").hexdigest())
        self.assertEqual(run("-C",self.remote,"rev-parse","refs/heads/main"),self.mainsha)

    def test_reviewed_zip_from_different_source_blocks_push(self):
        # Source changed AFTER release ZIP review: source and ZIP no longer agree.
        (self.src/"README.md").write_text("BMS24 CHANGED DEVELOPMENT NOTES\n")
        # README is outside installable plugin; this is intentionally allowed.
        self._repack()
        out = gi.run(self.bundle,str(self.remote),gi.DEFAULT_BRANCH,
                     install_zip=self.install,approved_sha256=self.approved_sha)
        self.assertTrue(out["candidate_verified"])
        self.assertFalse(out["pushed"])
        # If a production PHP source file changes, the check must fail.
        (self.src/"local/kbacompany/README.md").write_text("Changed plugin contents\n")
        self._repack()
        with self.assertRaisesRegex(gi.ImportHold,"differs from reviewed"):
            gi.run(self.bundle,str(self.remote),gi.DEFAULT_BRANCH,push=True,
                   install_zip=self.install,approved_sha256=self.approved_sha)
        self.assertEqual(run("-C",self.remote,"rev-parse","refs/heads/main"),self.mainsha)

    def test_missing_install_zip_fails(self):
        with self.assertRaisesRegex(gi.ImportHold,"missing"):
            gi.run(self.bundle,str(self.remote),gi.DEFAULT_BRANCH,
                   install_zip=self.root/"missing.zip",approved_sha256=self.approved_sha)

if __name__ == '__main__':
    unittest.main(verbosity=2)
