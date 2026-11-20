"""V1i synthetic process pause guard: static and negative unit tests."""
import ast
import os
from pathlib import Path
import signal
import sys
import tempfile
import unittest
from unittest.mock import patch

sys.path.insert(0,str(Path(__file__).resolve().parent))
import writer_pause_fault as w


class WriterPauseFaultTests(unittest.TestCase):
    def test_production_environment_rejected(self):
        with patch.dict(os.environ, {}, clear=True):
            with self.assertRaises(w.PauseHOLD):
                w.disposable_root()

    def test_partial_or_fake_ci_optin_rejected(self):
        vals={"CI":"true","GITHUB_ACTIONS":"true","BMS_WRITER_PAUSE_TEST":"1",
              "RUNNER_TEMP":"/home/runner/work/_temp"}
        with patch("pathlib.Path.is_dir",return_value=True):
            with patch.dict(os.environ,vals,clear=True):
                self.assertEqual(str(w.disposable_root()),vals["RUNNER_TEMP"])
            for k,v in (("CI","false"),("GITHUB_ACTIONS","false"),
                        ("BMS_WRITER_PAUSE_TEST","0"),
                        ("RUNNER_TEMP","/var/www/p706494/data/www/edu.kzn.mba")):
                env=dict(vals)
                env[k]=v
                with self.subTest(k=k),patch.dict(os.environ,env,clear=True):
                    with self.assertRaises(w.PauseHOLD):
                        w.disposable_root()

    def test_durable_receipt_contains_no_secrets_or_deploy_permission(self):
        with tempfile.TemporaryDirectory() as root:
            path=Path(root)/"receipt.json"
            w._write_receipt(path,{"schema":w.SCHEMA,"phase":"WRITERS_STOPPED",
                                   "simulation_only":True,"workers":[]})
            self.assertEqual(path.stat().st_mode & 0o777,0o600)
            self.assertIn("WRITERS_STOPPED",path.read_text())
            with self.assertRaises(FileExistsError):
                w._write_receipt(path,{"schema":"forged"})

    def test_no_real_moodle_or_production_services(self):
        tree=ast.parse(Path(w.__file__).read_text())
        imports={n.names[0].name for n in tree.body if isinstance(n,ast.Import)}
        self.assertNotIn("paramiko",imports)
        self.assertNotIn("requests",imports)
        source=Path(w.__file__).read_text()
        self.assertNotIn("ssh ",source)
        self.assertNotIn("mysqldump",source)
        self.assertIn('"real_moodle_writer_pause_verified": False',source)
        self.assertIn('"server_wide_lock_verified": False',source)
        self.assertIn('"production_backup_restore_verified": False',source)
        self.assertIn('"status": "HOLD"',source)

    def test_both_synthetic_writer_roles_are_declared(self):
        self.assertEqual(w.WORKERS,("synthetic_web","synthetic_cron"))

    def test_only_controller_kills_itself_after_fsynced_receipt(self):
        src=Path(w.__file__).read_text()
        portion=src.split("def _controller(",1)[1].split("def run()",1)[0]
        self.assertLess(portion.index("_write_receipt("),
                        portion.index("os.kill(os.getpid(), signal.SIGKILL)"))
        self.assertIn("_wait_stopped(pid)",portion)

    def test_cleanup_is_explicit_not_automatic_thaw_on_controller_death(self):
        src=Path(w.__file__).read_text()
        controller=src.split("def _controller(",1)[1].split("def run()",1)[0]
        self.assertNotIn("SIGCONT",controller)
        self.assertIn("signal.SIGCONT",src.split("finally:",1)[-1])

    def test_pid_not_arbitrary(self):
        for bad in (-1,0,1,True,"123"):
            with self.subTest(bad=bad),self.assertRaises(w.PauseHOLD):
                w._proc_state(bad)

    def test_rejected_invocation_without_ci_flags(self):
        with patch.dict(os.environ,{},clear=True):
            with self.assertRaises(w.PauseHOLD):
                w.run()


if __name__=="__main__":
    unittest.main()
