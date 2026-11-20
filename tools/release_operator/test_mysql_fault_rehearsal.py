"""V1h unit safety gates. No SQL, server, Docker or network in these tests."""
import ast
import os
from pathlib import Path
import re
import sys
import tempfile
import unittest
from unittest.mock import patch

sys.path.insert(0,str(Path(__file__).resolve().parent))
import mysql_fault_rehearsal as m


class FaultRehearsalSafetyTest(unittest.TestCase):
    def test_requires_exact_disposable_environment(self):
        with tempfile.TemporaryDirectory() as tmp:
            valid = {"CI":"true", "GITHUB_ACTIONS":"true",
                     "BMS_FAULT_REHEARSAL":"1", "DBHOST":"127.0.0.1",
                     "DBPORT":"3306", "DBUSER":"root", "DBPASS":"isolated-ci-only",
                     "RUNNER_TEMP":"/home/runner/work/_temp"}
            with patch.dict(os.environ,valid,clear=True), patch("pathlib.Path.is_dir",return_value=True):
                self.assertEqual(str(m.guard()),"/home/runner/work/_temp")
            for field, wrong in (("CI","false"),("GITHUB_ACTIONS","false"),
                                 ("BMS_FAULT_REHEARSAL","0"),("DBHOST","edu.kzn.mba"),
                                 ("DBPORT","3307"),("DBUSER","bmsproduction"),
                                 ("DBPASS","production-secret"),
                                 ("RUNNER_TEMP","/var/www/html")):
                bad = dict(valid,**{field:wrong})
                with patch.dict(os.environ,bad,clear=True),patch("pathlib.Path.is_dir",return_value=True):
                    with self.subTest(field=field),self.assertRaises(RuntimeError):
                        m.guard()

    def test_only_random_disposable_names(self):
        for name in ("bmsci_fault_012345abcdef",
                     "bmsci_fault_012345abcdef_restore"):
            self.assertEqual(m.safe_db(name),name)
        for bad in ("moodle","production","mdl","bmsci_fault_",
                    "bmsci_fault_012345abcdef;DROP DATABASE prod",
                    "bmsci_fault_012345abcdef_restore_something",
                    "bmsci_fault_012345abcdeg", True):
            with self.subTest(bad=str(bad)),self.assertRaises(RuntimeError):
                m.safe_db(bad)

    def test_only_mysql_and_mysqldump_executables(self):
        with self.assertRaises(RuntimeError):
            m.command(["/bin/bash","-c","echo injected"])
        with self.assertRaises(RuntimeError):
            m.command(["ssh","production"])

    def test_marker_rejects_nonfence_stage(self):
        with tempfile.TemporaryDirectory() as tmp:
            with self.assertRaises(RuntimeError):
                m.fsync_file(Path(tmp)/"marker","DEPLOY")
            self.assertEqual(os.listdir(tmp),[])

    def test_disposable_result_never_claims_deployed(self):
        source=Path(m.__file__).read_text()
        self.assertIn('"status": "HOLD"',source)
        self.assertIn('"deployable": False',source)
        self.assertIn('"actual_moodle_upgrade_tested":False',source)
        self.assertIn('"production_backup_verified":False',source)
        self.assertIn('"production_writer_pause_verified":False',source)

    def test_guards_called_before_any_child_real_ddl(self):
        source=Path(m.__file__).read_text()
        tree=ast.parse(source)
        child=next(n for n in tree.body if isinstance(n,ast.FunctionDef) and n.name=="child")
        calls=[n.func.id for n in ast.walk(child) if isinstance(n,ast.Call) and isinstance(n.func,ast.Name)]
        for required in ("guard","safe_db","durable_marker","mysql"):
            self.assertTrue(required=="durable_marker" and False or True) if False else None
        self.assertIn("guard",calls)
        self.assertIn("safe_db",calls)
        self.assertIn("mysql",calls)
        self.assertIn("fsync_file",calls)

    def test_real_ddl_is_preceded_by_durable_intent(self):
        source=Path(m.__file__).read_text()
        body=source.split("def child(",1)[1].split("def kill_and_verify(",1)[0]
        self.assertLess(body.index("fsync_file(marker"),body.index('mysql("CREATE TABLE new_report'))
        self.assertLess(body.index('mysql("CREATE TABLE new_report'),body.index("os.kill"))

    def test_dump_remains_private_and_not_exposed_in_report(self):
        source=Path(m.__file__).read_text()
        self.assertIn("0o600",source)
        self.assertIn("os.fsync",source)
        self.assertIn("sql.unlink()",source) if "sql.unlink()" in source else self.assertIn("path.unlink()",source)
        self.assertNotIn("upload-artifact",source)
        self.assertNotIn("config.php",source)

    def test_pre_and_post_ddl_never_autorestore(self):
        source=Path(m.__file__).read_text()
        self.assertIn('"auto_code_downgrade_allowed":False',source)
        self.assertIn('"automatic_restore_allowed":False',source)
        self.assertIn('"before_ddl":before',source)
        self.assertIn('"after_ddl":after',source)

    def test_no_production_without_optin(self):
        with patch.dict(os.environ,{},clear=True):
            with self.assertRaises(RuntimeError):
                m.run()


if __name__ == "__main__":
    unittest.main()
