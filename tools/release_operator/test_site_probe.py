"""V1c local PHP probe: safety checks without accessing a real Moodle site."""
from __future__ import annotations

import json
import os
from pathlib import Path
import shutil
import subprocess
import unittest

SCRIPT = Path(__file__).with_name("site_probe.php")


class SiteProbeTest(unittest.TestCase):
    def run_php(self, args=(), *, optin=False, stdin=""):
        env = dict(os.environ)
        env.pop("BMS_RELEASE_READONLY_PROBE", None)
        if optin:
            env["BMS_RELEASE_READONLY_PROBE"] = "1"
        return subprocess.run(
            ["php", str(SCRIPT), *list(args)],
            input=stdin, capture_output=True, text=True, env=env, timeout=10
        )

    def test_php_syntax_lint_real_file(self):
        self.assertIsNotNone(shutil.which("php"), "PHP CLI required to validate probe")
        p = subprocess.run(["php", "-l", str(SCRIPT)], capture_output=True, text=True)
        self.assertEqual(p.returncode, 0, p.stdout + p.stderr)

    def test_no_cli_opt_in_means_hold_and_no_server_action(self):
        p = self.run_php()
        self.assertEqual(p.returncode, 2)
        x = json.loads(p.stdout)
        self.assertEqual(x["status"], "HOLD")
        self.assertFalse(x["deployable"])
        self.assertEqual(x["actions_executed"], [])

    def test_invalid_local_root_holds_without_leaking_supplied_secret(self):
        p = self.run_php(
            ("/path/does-not-exist", "/tmp"),
            optin=True,
            stdin='{"password":"VERY_PRIVATE_SECRET"}'
        )
        self.assertEqual(p.returncode, 2)
        self.assertNotIn("VERY_PRIVATE_SECRET", p.stdout + p.stderr)
        self.assertEqual(json.loads(p.stdout)["status"], "HOLD")

    def test_no_arguments_with_opt_in_holds(self):
        p = self.run_php(optin=True)
        self.assertEqual(p.returncode, 2)
        self.assertEqual(json.loads(p.stdout)["status"], "HOLD")

    def test_no_deployment_surface_and_explicit_unknowns(self):
        code = SCRIPT.read_text(encoding="utf-8")
        self.assertIn("SET TRANSACTION READ ONLY", code)
        self.assertIn("PDO::ATTR_EMULATE_PREPARES => false", code)
        self.assertIn("information_schema.TABLES", code)
        self.assertIn("cron_expected_enabled' => 'NOT_MEASURED'", code)
        self.assertIn("release_lock_active' => 'NOT_MEASURED'", code)
        self.assertIn("origin_authenticated' => false", code)
        self.assertIn("'status' => 'HOLD'", code)
        self.assertNotIn("shell_exec(", code)
        self.assertNotIn("system(", code)
        self.assertNotIn("exec(", code.replace("->exec('SET TRANSACTION READ ONLY')", ""))
        for forbidden in ("DELETE FROM", "DROP TABLE", "ALTER TABLE", "TRUNCATE TABLE",
                          "UPDATE {", "INSERT INTO", "file_put_contents("):
            self.assertNotIn(forbidden, code)


if __name__ == "__main__":
    unittest.main()
