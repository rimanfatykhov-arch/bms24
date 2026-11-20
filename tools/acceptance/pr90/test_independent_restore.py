"""Offline negative tests for isolated PR90 Moodle full snapshot restore.

No MySQL or Moodle runtime here: fake subprocess verifies failure contracts.
The real MariaDB/Percona independent clone is exercised by pr90 rehearsal.py.
"""
from __future__ import annotations

import hashlib
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

from independent_restore import DB, IndependentRestoreHold, verify_independent_restore


def digest_tree(root):
    return {str(p.relative_to(root)): hashlib.sha256(p.read_bytes()).hexdigest()
            for p in root.rglob("*") if p.is_file()}


class FakeSQL:
    def __init__(self):
        self.database = None
        self.created = False
        self.dropped = False
        self.corrupt_dump = False
        self.commands = []

    def __call__(self, args, **kwargs):
        self.commands.append(list(args))
        if args[0] == "mysql" and "-e" in args:
            sql = args[args.index("-e") + 1]
            if sql.startswith("CREATE DATABASE " + DB + " "):
                self.created = True
                return
            if sql == "DROP DATABASE " + DB:
                self.dropped = True
                return
            raise AssertionError("unexpected SQL statement")
        if args[0] == "mysql" and DB in args:
            self.database = kwargs["stdin"].read()
            return
        if args[0] == "mysqldump" and DB in args:
            data = self.database if not self.corrupt_dump else b"corrupted"
            kwargs["stdout"].write(data)
            return
        raise AssertionError("unexpected fake command: " + str(args))


class IndependentRestoreTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.snapshot = self.root / "snapshot"
        (self.snapshot / "code" / "local" / "kbacompany").mkdir(parents=True)
        (self.snapshot / "data" / "filedir" / "aa").mkdir(parents=True)
        (self.snapshot / "code" / "config.php").write_bytes(b"CI-ONLY MOODLE CONFIG; NEVER REAL SECRETS")
        (self.snapshot / "code" / "local" / "kbacompany" / "version.php").write_bytes(b"2026111100")
        (self.snapshot / "data" / "filedir" / "aa" / "file").write_bytes(b"synthetic moodledata content")
        self.sql = self.snapshot / "db.sql"
        self.sql.write_bytes(b"-- isolated only\n" + b"CREATE TABLE test (id INT);\n" * 9)
        self.code = digest_tree(self.snapshot / "code")
        self.data = digest_tree(self.snapshot / "data")
        self.checksum = hashlib.sha256(self.sql.read_bytes()).hexdigest()
        self.fake = FakeSQL()

    def verify(self, **override):
        return verify_independent_restore(
            snapshot=override.get("snapshot", self.snapshot),
            root=self.root, dbargs=override.get("dbargs", ["-h127.0.0.1", "-P3306", "-uroot"]),
            cmd=self.fake, digest_tree=digest_tree,
            original_code=override.get("code", self.code),
            original_data=override.get("data", self.data),
            original_sql_sha256=override.get("checksum", self.checksum))

    def test_full_copy_and_sql_database_are_independent_and_identical(self):
        result = self.verify()
        self.assertEqual(result["status"], "PASS")
        self.assertTrue(result["independent_database_restored"])
        self.assertTrue(result["independent_code_and_config_verified"])
        self.assertTrue(result["independent_moodledata_verified"])
        self.assertFalse(result["independent_clone_booted"])
        self.assertFalse(result["production_or_shared_dev_accessed"])
        self.assertTrue(self.fake.created and self.fake.dropped)
        self.assertEqual(self.fake.database, self.sql.read_bytes())
        self.assertFalse((self.root / "independent-restore").exists())

    def test_corrupt_sql_restore_detected_even_if_mysql_exits_success(self):
        self.fake.corrupt_dump = True
        with self.assertRaisesRegex(IndependentRestoreHold, "entire SQL"):
            self.verify()
        self.assertTrue(self.fake.dropped)
        self.assertFalse((self.root / "independent-restore").exists())

    def test_snapshot_sql_changes_after_capture_are_detected_before_database_ddl(self):
        self.sql.write_bytes(self.sql.read_bytes() + b"AFTER_CAPTURE_TAMPER")
        with self.assertRaisesRegex(IndependentRestoreHold, "SQL mutated"):
            self.verify()
        self.assertFalse(self.fake.commands)

    def test_code_and_moodledata_changes_detected_before_restore(self):
        (self.snapshot / "code" / "config.php").write_bytes(b"modified")
        with self.assertRaisesRegex(IndependentRestoreHold, "code/config"):
            self.verify()
        self.assertFalse(self.fake.commands)

    def test_missing_config_rejected(self):
        (self.snapshot / "code" / "config.php").unlink()
        self.code = digest_tree(self.snapshot / "code")
        with self.assertRaisesRegex(IndependentRestoreHold, "config.php"):
            self.verify()
        self.assertFalse(self.fake.commands)

    def test_wrong_snapshot_root_rejected(self):
        with self.assertRaisesRegex(IndependentRestoreHold, "active PR90 snapshot"):
            self.verify(snapshot=self.root / "not-the-accepted-snapshot")

    def test_existing_clone_directory_must_not_be_reused(self):
        (self.root / "independent-restore").mkdir()
        with self.assertRaisesRegex(IndependentRestoreHold, "must not exist"):
            self.verify()
        self.assertFalse(self.fake.commands)

    def test_not_approved_without_loopback_client(self):
        with self.assertRaisesRegex(IndependentRestoreHold, "isolated MySQL"):
            self.verify(dbargs=["-hproduction-host", "-uroot"])
        self.assertFalse(self.fake.commands)

    def test_failed_database_creation_does_not_claim_success(self):
        def fail_create(args, **kwargs):
            raise subprocess.CalledProcessError(1, args)
        self.fake = fail_create
        with self.assertRaises(subprocess.CalledProcessError):
            self.verify()
        self.assertFalse((self.root / "independent-restore").exists())


if __name__ == "__main__":
    unittest.main()
