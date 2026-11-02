#!/usr/bin/env python3
"""No-network regression for the BMS24 GitHub branch import contract."""
import io
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from contextlib import redirect_stdout

sys.path.insert(0, str(Path(__file__).resolve().parent))
import github_import as gi
from build_install import build


def run(*args, cwd=None):
    cmd = ["git", "-c", "user.name=BMS CI", "-c", "user.email=bms-ci@example.test", *map(str,args)]
    cp = subprocess.run(cmd, cwd=cwd, capture_output=True, text=True, check=True)
    return cp.stdout.strip()


class GitHubImportTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.remote = self.root / "remote.git"
        run("init", "--bare", self.remote)
        self.orig = self.root / "original"
        run("clone", self.remote, self.orig)
        run("-C", self.orig, "checkout", "-b", "main")
        (self.orig / "README.md").write_text("GitHub old code\n")
        run("-C", self.orig, "add", ".")
        run("-C", self.orig, "commit", "-m", "old 1645")
        run("-C", self.orig, "push", "origin", "main")
        run("-C", self.remote, "symbolic-ref", "HEAD", "refs/heads/main")
        self.mainsha = run("-C", self.remote, "rev-parse", "refs/heads/main")
        self.src = self.root / "source"
        run("init", "-b", "main", self.src)
        for name, content in {
            "AGENTS.md":"# agents\n",
            "tools/dev_ai_gate.py":"# checker\n",
            "local/kbacompany/db/upgrade.php":"<?php // checkpoint\n",
            "local/kbacompany/version.php":"<?php\n$plugin->version = 2026101879;\n",
            "README.md":"# BMS24 latest\n",
        }.items():
            file = self.src / name
            file.parent.mkdir(parents=True,exist_ok=True)
            file.write_text(content)
        run("-C", self.src, "add", ".")
        run("-C", self.src, "commit", "-m", "latest 1879")
        self.bundle = self.root / "source.bundle"
        run("-C", self.src, "bundle", "create", self.bundle, "main")
        self.install = self.root / "reviewed-install.zip"
        self.approved_sha = build(self.src / "local" / "kbacompany", self.install)["zip_sha256"]

    def test_default_does_not_push(self):
        out = gi.run(self.bundle, str(self.remote), gi.DEFAULT_BRANCH)
        self.assertEqual(out["status"],"PREPARED")
        self.assertFalse(out["pushed"])
        self.assertEqual(run("-C", self.remote, "rev-parse", "refs/heads/main"),self.mainsha)
        self.assertNotIn("refs/heads/import/", run("-C", self.remote, "show-ref"))

    def test_explicit_push_preserves_both_histories(self):
        out = gi.run(self.bundle, str(self.remote), gi.DEFAULT_BRANCH, push=True,
                      install_zip=self.install, approved_sha256=self.approved_sha)
        self.assertTrue(out["pushed"])
        self.assertEqual(run("-C", self.remote, "rev-parse", "refs/heads/main"),self.mainsha)
        head=run("-C", self.remote,"rev-parse", "refs/heads/"+gi.DEFAULT_BRANCH)
        self.assertEqual(head, out["commit"])
        parents=run("-C", self.remote,"rev-list", "--parents", "-n", "1", head).split()
        self.assertEqual(len(parents),3)
        self.assertEqual(parents[1],self.mainsha)
        self.assertEqual(parents[2],run("-C", self.src,"rev-parse", "HEAD"))
        self.assertEqual(run("-C",self.remote,"rev-parse",head+"^{tree}"),run("-C",self.src,"rev-parse","HEAD^{tree}"))

    def test_existing_import_branch_is_never_overwritten(self):
        gi.run(self.bundle, str(self.remote), gi.DEFAULT_BRANCH, push=True,
                      install_zip=self.install, approved_sha256=self.approved_sha)
        with self.assertRaisesRegex(gi.ImportHold,"already exists"):
            gi.run(self.bundle, str(self.remote), gi.DEFAULT_BRANCH, push=True,
                      install_zip=self.install, approved_sha256=self.approved_sha)

    def test_uses_previous_import_branch_as_base(self):
        run("-C",self.orig,"checkout","-b","import/build2026101876")
        (self.orig/"import.md").write_text("history\n")
        run("-C",self.orig,"add",".")
        run("-C",self.orig,"commit","-m","previous import")
        previous=run("-C",self.orig,"rev-parse","HEAD")
        run("-C",self.orig,"push","origin","import/build2026101876")
        out=gi.run(self.bundle,str(self.remote),gi.DEFAULT_BRANCH)
        self.assertEqual(out["base"],previous)

    def test_rejects_incorrect_version(self):
        (self.src/"local/kbacompany/version.php").write_text("<?php $plugin->version = 2026101876;")
        self._repack()
        with self.assertRaisesRegex(gi.ImportHold,"expected"):
            gi.run(self.bundle,str(self.remote),gi.DEFAULT_BRANCH)

    def test_rejects_secrets(self):
        (self.src/"local/kbacompany/config.php").write_text("secret=123")
        self._repack()
        with self.assertRaisesRegex(gi.ImportHold,"prohibited"):
            gi.run(self.bundle,str(self.remote),gi.DEFAULT_BRANCH)

    def test_rejects_tracked_symlink(self):
        (self.src/"symlink").symlink_to("README.md")
        self._repack()
        with self.assertRaisesRegex(gi.ImportHold,"symlinks"):
            gi.run(self.bundle,str(self.remote),gi.DEFAULT_BRANCH)

    def test_rejects_main_as_target(self):
        with self.assertRaisesRegex(gi.ImportHold,"branch must"):
            gi.run(self.bundle,str(self.remote),"main",push=False)

    def test_no_credentials_in_urls(self):
        with self.assertRaisesRegex(gi.ImportHold,"credential"):
            gi.run(self.bundle,"https://user:token@github.com/private/repo",gi.DEFAULT_BRANCH)

    def test_missing_bundle(self):
        with self.assertRaisesRegex(gi.ImportHold,"not found"):
            gi.run(self.root/"no.bundle",str(self.remote),gi.DEFAULT_BRANCH)

    def test_read_only_actions_on_failed_push(self):
        gi.run(self.bundle,str(self.remote),gi.DEFAULT_BRANCH,push=True,
                       install_zip=self.install, approved_sha256=self.approved_sha)
        self.assertEqual(run("-C", self.remote,"rev-parse", "refs/heads/main"),self.mainsha)
        with self.assertRaises(gi.ImportHold):
            gi.run(self.bundle,str(self.remote),gi.DEFAULT_BRANCH,push=True,
                       install_zip=self.install, approved_sha256=self.approved_sha)
        self.assertEqual(run("-C", self.remote,"rev-parse", "refs/heads/main"),self.mainsha)

    def _repack(self):
        run("-C",self.src,"add","-A")
        run("-C",self.src,"commit","-m","alter source")
        self.bundle.unlink()
        run("-C",self.src,"bundle","create",self.bundle,"main")


if __name__=="__main__":
    unittest.main(verbosity=2)
