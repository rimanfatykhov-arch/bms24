"""V1c fault injection against synthetic-only durable release journal.

No Moodle, database, remote credentials or production commands are executed.
"""
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
from phase_journal import (JournalHold, PHASES, load, record,
                           recovery_guidance, status)

RELEASE_ID = "BMS24-R1R2-20261119"


class PhaseJournalTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.path = Path(self.temp.name) / "private-journal.jsonl"

    def start_through(self, phase):
        value = None
        for step in PHASES[:PHASES.index(phase) + 1]:
            value = record(self.path, RELEASE_ID, step)
        return value

    def test_all_phases_finish_simulation_but_cannot_authorize_deploy(self):
        result = self.start_through("SIMULATED_COMPLETE")
        self.assertEqual(result["phase"], "SIMULATED_COMPLETE")
        self.assertEqual(result["sequence"], len(PHASES) - 1)
        self.assertFalse(result["deployable"])
        self.assertTrue(result["simulated"])
        self.assertEqual(result["actions_executed"], [])
        self.assertEqual(len(load(self.path, RELEASE_ID)), len(PHASES))
        self.assertFalse(result["recovery"]["automatic_restore"])
        self.assertFalse(result["recovery"]["code_only_downgrade_allowed"])

    def test_init_required_and_cannot_skip_or_duplicate_phase(self):
        with self.assertRaises(JournalHold):
            record(self.path, RELEASE_ID, "DDL_INTENT")
        record(self.path, RELEASE_ID, "INIT")
        with self.assertRaises(JournalHold):
            record(self.path, RELEASE_ID, "INIT")
        with self.assertRaises(JournalHold):
            record(self.path, RELEASE_ID, "WRITER_PAUSE_ATTESTED")
        self.assertEqual(status(self.path, RELEASE_ID)["phase"], "INIT")

    def test_wrong_release_or_unknown_phase_rejected(self):
        record(self.path, RELEASE_ID, "INIT")
        with self.assertRaises(JournalHold):
            record(self.path, "BMS24-OTHER-20261119", "OPERATOR_LOCK_ATTESTED")
        with self.assertRaises(JournalHold):
            load(self.path, "BMS24-OTHER-20261119")
        with self.assertRaises(JournalHold):
            record(self.path, RELEASE_ID, "PRODUCTION_DEPLOY")
        with self.assertRaises(JournalHold):
            record(self.path, "invalid", "OPERATOR_LOCK_ATTESTED")

    def test_pre_ddl_recovery_never_autoresumes_writers(self):
        result = self.start_through("ISOLATED_RESTORE_ATTESTED")
        self.assertIn("PRE_DDL_HOLD", result["recovery"]["policy"])
        self.assertFalse(result["recovery"]["automatic_writer_resume"])
        self.assertFalse(result["recovery"]["automatic_restore"])
        self.assertFalse(result["recovery"]["code_only_downgrade_allowed"])

    def test_post_ddl_unverified_upgrade_is_maintenance_hold(self):
        result = self.start_through("DDL_INTENT")
        self.assertEqual(result["recovery"]["policy"],
                         "POST_DDL_MAINTENANCE_HOLD_NO_CODE_ONLY_DOWNGRADE")
        self.assertFalse(result["recovery"]["automatic_writer_resume"])

    def test_after_upgrade_requires_compatible_code_and_feature_off(self):
        result = self.start_through("UPGRADE_ATTESTED")
        self.assertEqual(result["recovery"]["policy"],
                         "VERIFY_1601_COMPATIBLE_CODE_FEATURE_OFF_NO_AUTOMATIC_DB_RESTORE")
        self.assertFalse(result["recovery"]["automatic_restore"])

    def test_sigkill_after_durable_ddl_intent_replays_recovery_hold(self):
        self.start_through("ISOLATED_RESTORE_ATTESTED")
        snippet = (
            "import sys,os,signal\n"
            "sys.path.insert(0, " + repr(str(Path(__file__).resolve().parent)) + ")\n"
            "from pathlib import Path\n"
            "from phase_journal import record\n"
            "record(Path(" + repr(str(self.path)) + "), " + repr(RELEASE_ID) + ", 'DDL_INTENT')\n"
            "os.kill(os.getpid(),signal.SIGKILL)\n"
        )
        p = subprocess.run([sys.executable, "-c", snippet], capture_output=True, text=True)
        self.assertEqual(p.returncode, -signal.SIGKILL, p.stderr)
        result = status(self.path, RELEASE_ID)
        self.assertEqual(result["phase"], "DDL_INTENT")
        self.assertEqual(result["recovery"]["policy"],
                         "POST_DDL_MAINTENANCE_HOLD_NO_CODE_ONLY_DOWNGRADE")
        self.assertFalse(result["deployable"])

    def test_sigkill_before_ddl_keeps_separate_recovery_policy(self):
        self.start_through("OPERATOR_LOCK_ATTESTED")
        snippet = (
            "import sys,os,signal\n"
            "sys.path.insert(0," + repr(str(Path(__file__).resolve().parent)) + ")\n"
            "from pathlib import Path\n"
            "from phase_journal import record\n"
            "record(Path(" + repr(str(self.path)) + "), " + repr(RELEASE_ID) +
            ", 'WRITER_PAUSE_ATTESTED')\n"
            "os.kill(os.getpid(),signal.SIGKILL)\n"
        )
        p = subprocess.run([sys.executable, "-c", snippet], capture_output=True, text=True)
        self.assertEqual(p.returncode, -signal.SIGKILL, p.stderr)
        self.assertIn("PRE_DDL_HOLD", status(self.path, RELEASE_ID)["recovery"]["policy"])

    def test_partial_tail_is_not_ignored_or_automatically_repaired(self):
        record(self.path, RELEASE_ID, "INIT")
        with self.path.open("ab") as stream:
            stream.write(b'{"schema":"truncated"')
        with self.assertRaisesRegex(JournalHold, "incomplete"):
            status(self.path, RELEASE_ID)
        with self.assertRaises(JournalHold):
            record(self.path, RELEASE_ID, "OPERATOR_LOCK_ATTESTED")

    def test_hash_chain_tampering_fails_closed(self):
        self.start_through("OPERATOR_LOCK_ATTESTED")
        payload = self.path.read_bytes()
        self.path.write_bytes(payload.replace(b"OPERATOR_LOCK_ATTESTED", b"WRITER_PAUSE_ATTESTED"))
        with self.assertRaises(JournalHold):
            status(self.path, RELEASE_ID)

    def test_local_lock_contention_rejected(self):
        record(self.path, RELEASE_ID, "INIT")
        lock = self.path.with_name(self.path.name + ".lock")
        fd = os.open(lock, os.O_RDWR | os.O_NOFOLLOW)
        try:
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
            with self.assertRaisesRegex(JournalHold, "already locked"):
                record(self.path, RELEASE_ID, "OPERATOR_LOCK_ATTESTED")
        finally:
            os.close(fd)
        self.assertEqual(status(self.path, RELEASE_ID)["phase"], "INIT")

    def test_refuse_public_parent_directory_and_journal_symlink(self):
        pub = Path(self.temp.name) / "public"
        pub.mkdir(mode=0o755)
        with self.assertRaisesRegex(JournalHold, "mode 0700"):
            record(pub / "journal.jsonl", RELEASE_ID, "INIT")
        other = Path(self.temp.name) / "real"
        other.write_text("{}", encoding="utf-8")
        self.path.symlink_to(other)
        with self.assertRaisesRegex(JournalHold, "symlink"):
            record(self.path, RELEASE_ID, "INIT")

    def test_recovery_helper_refuses_unknown_phase(self):
        with self.assertRaises(JournalHold):
            recovery_guidance("PRODUCTION_GO")

    def test_journal_no_external_action_or_approval_fields(self):
        result = record(self.path, RELEASE_ID, "INIT")
        self.assertEqual(result["actions_executed"], [])
        data = json.loads(self.path.read_text().splitlines()[0])
        self.assertEqual(data["target"], "synthetic")
        self.assertTrue(data["simulation_only"])
        self.assertNotIn("owner_approved", data)
        self.assertNotIn("password", data)


if __name__ == "__main__":
    unittest.main()
