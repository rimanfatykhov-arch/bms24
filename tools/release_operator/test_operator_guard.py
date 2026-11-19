"""V1d durable reservation failure simulations; no server or DB calls."""
from __future__ import annotations

import fcntl
import json
import os
from pathlib import Path
import signal
import subprocess
import sys
import tempfile
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parent))
from operator_guard import GuardHold, ORDER, acquire, advance, inspect

RELEASE = "BMS24-R1R2-20261119"


class OperatorGuardTest(unittest.TestCase):
    def setUp(self):
        self.t = tempfile.TemporaryDirectory()
        self.addCleanup(self.t.cleanup)
        self.root = Path(self.t.name)
        self.root.chmod(0o700)

    def acquire(self):
        return acquire(self.root, RELEASE)

    def test_initial_empty_guard_is_hold_not_approval(self):
        s = inspect(self.root)
        self.assertEqual(s["status"], "HOLD")
        self.assertFalse(s["deployable"])
        self.assertFalse(s["reservation_exists"])

    def test_reservation_is_durable_and_disallows_second_operator(self):
        s = self.acquire()
        self.assertEqual(s["phase"], "RESERVED")
        self.assertEqual(s["actions_executed"], [])
        self.assertTrue(inspect(self.root)["reservation_exists"])
        with self.assertRaisesRegex(GuardHold, "manual recovery"):
            self.acquire()

    def test_crash_after_acquire_does_not_allow_automatic_takeover(self):
        script = (
            "import sys,os,signal\n"
            "sys.path.insert(0," + repr(str(Path(__file__).resolve().parent)) + ")\n"
            "from pathlib import Path\n"
            "from operator_guard import acquire\n"
            "acquire(Path(" + repr(str(self.root)) + "), " + repr(RELEASE) + ")\n"
            "os.kill(os.getpid(),signal.SIGKILL)\n"
        )
        p = subprocess.run([sys.executable, "-c", script], capture_output=True, text=True)
        self.assertEqual(p.returncode, -signal.SIGKILL, p.stderr)
        self.assertEqual(inspect(self.root)["phase"], "RESERVED")
        with self.assertRaises(GuardHold):
            self.acquire()

    def test_lock_contention_fails_closed(self):
        self.acquire()
        fd = os.open(self.root / "operator.lock", os.O_RDWR | os.O_NOFOLLOW)
        try:
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
            s = inspect(self.root)
            self.assertEqual(s["status"], "HOLD")
            with self.assertRaisesRegex(GuardHold, "local model operator"):
                advance(self.root, RELEASE, "0" * 32, "WRITER_PAUSE_ASSERTED")
        finally:
            os.close(fd)

    def test_symbolic_ddl_fence_survives_process_kill(self):
        token = self.acquire()["lease_id"]
        for phase in ("WRITER_PAUSE_ASSERTED", "BACKUP_RESTORE_ASSERTED"):
            advance(self.root, RELEASE, token, phase)
        script = (
            "import sys,os,signal\n"
            "sys.path.insert(0," + repr(str(Path(__file__).resolve().parent)) + ")\n"
            "from pathlib import Path\n"
            "from operator_guard import advance\n"
            "advance(Path(" + repr(str(self.root)) + "), " + repr(RELEASE) + ", " +
            repr(token) + ", 'DDL_INTENT')\n"
            "os.kill(os.getpid(),signal.SIGKILL)\n"
        )
        p = subprocess.run([sys.executable, "-c", script], capture_output=True, text=True)
        self.assertEqual(p.returncode, -signal.SIGKILL, p.stderr)
        s = inspect(self.root)
        self.assertEqual(s["phase"], "DDL_INTENT")
        self.assertEqual(s["recovery_policy"],
                         "POST_DDL_MAINTENANCE_HOLD_NO_CODE_ONLY_DOWNGRADE")
        self.assertFalse(s["automatic_writer_resume"])
        self.assertFalse(s["automatic_db_restore"])
        with self.assertRaises(GuardHold):
            self.acquire()

    def test_unrelated_release_or_invalid_token_cannot_advance(self):
        token = self.acquire()["lease_id"]
        with self.assertRaises(GuardHold):
            advance(self.root, "BMS24-OTHER-20261119", token, "WRITER_PAUSE_ASSERTED")
        with self.assertRaises(GuardHold):
            advance(self.root, RELEASE, "0" * 32, "WRITER_PAUSE_ASSERTED")
        with self.assertRaises(GuardHold):
            advance(self.root, RELEASE, token, "DDL_INTENT")
        self.assertEqual(inspect(self.root)["phase"], "RESERVED")

    def test_completed_simulation_still_has_lease_and_requires_manual_recovery(self):
        token = self.acquire()["lease_id"]
        for phase in ORDER[1:]:
            s = advance(self.root, RELEASE, token, phase)
        self.assertEqual(s["phase"], "MODEL_COMPLETE")
        self.assertTrue(s["manual_recovery_required"])
        self.assertFalse(s["deployable"])
        with self.assertRaises(GuardHold):
            self.acquire()

    def test_tamper_and_partial_marker_never_permit_new_acquire(self):
        self.acquire()
        state = self.root / "reservation.json"
        contents = state.read_bytes()
        state.write_bytes(contents[:-1])
        with self.assertRaises(GuardHold):
            inspect(self.root)
        with self.assertRaises(GuardHold):
            self.acquire()

    def test_hash_tamper_and_symlink_refused(self):
        self.acquire()
        state = self.root / "reservation.json"
        contents = state.read_bytes()
        state.write_bytes(contents.replace(b"RESERVED", b"DDL_INTENT"))
        with self.assertRaises(GuardHold):
            inspect(self.root)
        state.unlink()
        shadow = self.root / "shadow.json"
        shadow.write_text("{}", encoding="utf-8")
        state.symlink_to(shadow)
        with self.assertRaises(GuardHold):
            inspect(self.root)

    def test_private_directory_and_invalid_release_required(self):
        self.root.chmod(0o755)
        with self.assertRaises(GuardHold):
            self.acquire()
        self.root.chmod(0o700)
        with self.assertRaises(GuardHold):
            acquire(self.root, "invalid")

    def test_symbolic_states_do_not_claim_real_writer_pause(self):
        token = self.acquire()["lease_id"]
        for phase in ORDER[1:]:
            s = advance(self.root, RELEASE, token, phase)
            self.assertFalse(s["writer_pause_actually_held"])
            self.assertFalse(s["automatic_lock_takeover"])
            self.assertFalse(s["code_only_downgrade_allowed"])

    def test_model_reservation_contains_no_connection_secrets(self):
        self.acquire()
        raw = json.loads((self.root / "reservation.json").read_text(encoding="utf-8"))
        self.assertEqual(raw["target"], "synthetic")
        self.assertTrue(raw["simulation_only"])
        self.assertNotIn("ssh_key", raw)
        self.assertNotIn("database_password", raw)


if __name__ == "__main__":
    unittest.main()
