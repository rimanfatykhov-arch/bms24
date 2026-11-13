#!/usr/bin/env python3
"""Isolated unit tests for Dev AI policy and deterministic packaging."""
from __future__ import annotations

import copy
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
import zipfile

ROOT = Path(__file__).resolve().parents[1]


def load(name):
    spec = importlib.util.spec_from_file_location(name, ROOT / "tools" / f"{name}.py")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


gate = load("dev_ai_gate")
builder = load("build_install")


class PolicyTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.policy = gate.load_json(ROOT / "dev_ai/agents.json")

    def assertHeld(self, edit):
        p = copy.deepcopy(self.policy)
        edit(p)
        self.assertTrue(gate.validate_policy(p))

    def test_valid_baseline(self):
        self.assertEqual(gate.validate_policy(self.policy), [])

    def test_repository_baseline(self):
        self.assertEqual(gate.validate_repo(ROOT), [])

    def test_no_automatic_execution(self):
        self.assertHeld(lambda p: p.update(execution_enabled=True))

    def test_default_deny_required(self):
        self.assertHeld(lambda p: p.update(default_policy="allow"))

    def test_production_capability_denied(self):
        self.assertHeld(lambda p: p["agents"][2]["capabilities"].append("deploy_production"))

    def test_human_approval_required(self):
        self.assertHeld(lambda p: p["human_approval_required"].remove("release_production"))

    def test_duplicate_role(self):
        self.assertHeld(lambda p: p["agents"].append(copy.deepcopy(p["agents"][0])))

    def test_missing_security_role(self):
        self.assertHeld(lambda p: p.update(agents=[a for a in p["agents"] if a["id"] != "security"]))

    def test_unsafe_environment(self):
        self.assertHeld(lambda p: p["environments"].append("production"))

    def test_invalid_capability_schema(self):
        self.assertHeld(lambda p: p["agents"][2].update(capabilities="edit_worktree"))

    def test_language_keys_equal(self):
        self.assertEqual(gate.validate_languages("$string['a'] = 'A';", "$string['a'] = 'B';"), [])

    def test_missing_translation_rejected(self):
        self.assertTrue(gate.validate_languages("$string['a']=1; $string['b']=2;", "$string['a']=1;"))

    def test_duplicate_translations_rejected(self):
        self.assertTrue(gate.validate_languages("$string['a']=1; $string['a']=2;", "$string['a']=1;"))

    def test_policy_has_no_duplicate_json_keys(self):
        with tempfile.TemporaryDirectory() as d:
            path = Path(d) / "bad.json"
            path.write_text('{"default_policy":"deny","default_policy":"allow"}')
            with self.assertRaises(ValueError):
                gate.load_json(path)


class PackageTests(unittest.TestCase):
    def make_sample(self, parent):
        src = parent / "sample"
        src.mkdir()
        (src / "version.php").write_text('<?php $plugin->version = 2026101872;')
        (src / "a.txt").write_text('alpha')
        (src / "sub").mkdir()
        (src / "sub/b.txt").write_text('beta')
        return src

    def test_identical_source_identical_zip(self):
        with tempfile.TemporaryDirectory() as td:
            root = Path(td)
            src = self.make_sample(root)
            one, two = root / "one.zip", root / "two.zip"
            a = builder.build(src, one, root / "manifest.json")
            b = builder.build(src, two)
            self.assertEqual(one.read_bytes(), two.read_bytes())
            self.assertEqual(a["zip_sha256"], b["zip_sha256"])
            self.assertEqual(a["files"], 3)
            self.assertEqual(json.loads((root / "manifest.json").read_text())["artifact"]["version"], 2026101872)
            with zipfile.ZipFile(one) as zf:
                self.assertEqual(zf.read("kbacompany/a.txt"), b"alpha")
                self.assertEqual(zf.namelist(), sorted(zf.namelist()))
                self.assertEqual(len(set(i.date_time for i in zf.infolist())), 1)

    def test_changed_source_changes_hash(self):
        with tempfile.TemporaryDirectory() as td:
            root = Path(td)
            src = self.make_sample(root)
            a = builder.build(src, root / "a.zip")
            (src / "a.txt").write_text('changed')
            b = builder.build(src, root / "b.zip")
            self.assertNotEqual(a["zip_sha256"], b["zip_sha256"])

    def test_secret_file_is_rejected(self):
        with tempfile.TemporaryDirectory() as td:
            root = Path(td)
            src = self.make_sample(root)
            (src / "config.php").write_text('<?php // secret')
            with self.assertRaises(ValueError):
                builder.build(src, root / "out.zip")

    def test_symlink_is_rejected(self):
        with tempfile.TemporaryDirectory() as td:
            root = Path(td)
            src = self.make_sample(root)
            (src / "alias").symlink_to(src / "a.txt")
            with self.assertRaises(ValueError):
                builder.build(src, root / "out.zip")

    def test_output_inside_source_is_rejected(self):
        with tempfile.TemporaryDirectory() as td:
            root = Path(td)
            src = self.make_sample(root)
            with self.assertRaises(ValueError):
                builder.build(src, src / "out.zip")

    def test_cache_files_excluded(self):
        with tempfile.TemporaryDirectory() as td:
            root = Path(td)
            src = self.make_sample(root)
            (src / "__pycache__").mkdir()
            (src / "__pycache__/stuff.pyc").write_bytes(b"bad")
            a = builder.build(src, root / "out.zip")
            self.assertEqual(a["files"], 3)


if __name__ == "__main__":
    unittest.main(verbosity=2)
