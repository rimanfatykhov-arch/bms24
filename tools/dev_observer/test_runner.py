from contextlib import closing, redirect_stdout
from datetime import datetime, timedelta, timezone
import io
import json
import os
from pathlib import Path
import sqlite3
import subprocess
import tempfile
from threading import Event, Thread
from types import SimpleNamespace
import unittest
from unittest.mock import patch

from runner import (ADAPTER, CARD, WEB, authorize, clean_checkout, codex, digest, encoded,
                    layout, main, start, Blocked)
from scheduler import build_plan


class RunnerTests(unittest.TestCase):
    def setUp(self):
        tmp = tempfile.TemporaryDirectory()
        self.addCleanup(tmp.cleanup)
        self.root = Path(tmp.name).resolve()
        self.work = self.root / "checkout"
        profile = self.root / "profile"
        self.work.mkdir()
        profile.mkdir()
        cli = self.root / "codex.exe"
        cli.write_bytes(b"synthetic adapter binary")
        self.addCleanup(patch.stopall)
        self.clock = datetime(2026, 11, 28, tzinfo=timezone.utc)
        self.clock_mock = patch("runner.now", return_value=self.clock).start()
        self.checkout = patch("runner.clean_checkout").start()
        self.args = SimpleNamespace(**{n: self.root / (n + ".json")
                                       for n in ("approval", "plan", "snapshot", "queue", "session")},
                                    execute=True, reconcile=False)
        base = "a" * 40
        item = {"number": 1, "kind": "issue", "executor": "Codex", "head": base,
                "tree": None, "base": None, "dependencies": []}
        self.snapshot = dict(schema_version=1, main=base, complete=True, consistent=True, fresh=True, errors=[],
                             collected_at=self.clock.isoformat(), observations=[item],
                             findings=[dict(item, status="READY", reason="observed_preconditions_met")])
        self.queue = dict(schema_version=1, main=base, registry_ref=WEB + "/issues/198",
                          priorities_ref=WEB + "/issues/205",
                          tasks=[dict(item, action="continue_work", branch="feat/synthetic", priority=0, hold=False)])
        self.go = dict(schema_version=1, decision="GO", repository=WEB, actor="Codex", adapter=ADAPTER,
                       card=CARD["task"], approval_id="local-test", number=1, base_sha=base, result="result.json",
                       not_before=self.clock.isoformat(), expires_at=(self.clock + timedelta(minutes=10)).isoformat(),
                       workspace=str(self.work), codex_home=str(profile), codex_cli=str(cli),
                       codex_cli_sha256=digest(cli.read_bytes()))
        self.proof = dict(workspace=str(self.work), observed_at=self.clock.isoformat(), state="vacant", session_id=None)
        self.prepare()

    def prepare(self, plan=None):
        if plan is None:
            plan = build_plan(self.snapshot, self.queue, self.clock)
        for key, value in (("plan", plan), ("snapshot", self.snapshot), ("queue", self.queue),
                           ("session", self.proof)):
            raw = encoded(value)
            getattr(self.args, key).write_bytes(raw)
            self.go[key + "_sha256"] = digest(raw)
        self.args.approval.write_bytes(encoded(self.go))
        self.args.approval_sha256 = digest(encoded(self.go))

    def run_pilot(self, adapter):
        go, identity, plan_hash = authorize(self.args, self.clock_mock.return_value)
        return start(self.args, go, identity, plan_hash, layout(self.args, go), adapter)

    def positive(self, _cli, _profile, _scratch, started, seconds):
        self.assertGreater(seconds, 0)
        started("12345678-1234-1234-1234-123456789abc")
        return "REVIEW_READY", "agent_completed", "12345678-1234-1234-1234-123456789abc", {
            "task": CARD["task"], "total": 37}

    def never_launch(self, *_args):
        self.fail("unexpected adapter launch")

    def test_dry_run_has_no_journal_or_launch(self):
        argv = []
        for option in ("approval", "plan", "snapshot", "queue", "session", "approval_sha256"):
            argv += ["--" + option.replace("_", "-"), str(getattr(self.args, option))]
        with redirect_stdout(io.StringIO()) as output, patch("runner.start", side_effect=self.never_launch):
            self.assertEqual(main(argv), 0)
        self.assertEqual("dry-run", json.loads(output.getvalue())["mode"])
        self.assertFalse((self.root / "pilot.sqlite3").exists())

    def test_one_success_and_idempotent_receipt(self):
        result = self.run_pilot(self.positive)
        self.assertEqual(result, self.run_pilot(self.never_launch))
        self.assertEqual("REVIEW_READY", result["status"])
        path = next(self.root.glob("run-*/result.json"))
        self.assertEqual(digest(path.read_bytes()), result["result_sha256"])
        self.assertEqual("Claude", result["reviewer"])

    def test_hold_partial_false_plan_and_stale_evidence(self):
        self.queue["tasks"][0]["hold"] = True
        self.prepare()
        with self.assertRaisesRegex(Blocked, "task_blocked_or_unassigned"):
            self.run_pilot(self.never_launch)
        self.queue["tasks"][0]["hold"] = False
        self.snapshot["complete"] = False
        self.prepare()
        with self.assertRaisesRegex(Blocked, "partial_snapshot"):
            self.run_pilot(self.never_launch)
        self.snapshot["complete"] = True
        plan = build_plan(self.snapshot, self.queue, self.clock)
        self.prepare(dict(plan, recommendations=[]))
        with self.assertRaisesRegex(Blocked, "scheduler_plan_mismatch"):
            self.run_pilot(self.never_launch)
        self.snapshot["collected_at"] = (self.clock - timedelta(hours=25)).isoformat()
        self.prepare()
        with self.assertRaisesRegex(Blocked, "stale_snapshot"):
            self.run_pilot(self.never_launch)

    def test_assignment_dependencies_and_sha(self):
        task = self.queue["tasks"][0]
        task["executor"] = "Claude"
        self.prepare()
        with self.assertRaisesRegex(Blocked, "task_blocked_or_unassigned"):
            self.run_pilot(self.never_launch)
        task["executor"], task["dependencies"] = "Codex", [2]
        self.prepare()
        with self.assertRaisesRegex(Blocked, "task_blocked_or_unassigned"):
            self.run_pilot(self.never_launch)
        task["dependencies"] = []
        self.go["base_sha"] = "b" * 40
        self.prepare()
        with self.assertRaisesRegex(Blocked, "identity_mismatch"):
            self.run_pilot(self.never_launch)

    def test_busy_session_and_unsupported_adapter(self):
        self.proof["state"] = "busy"
        self.prepare()
        with self.assertRaisesRegex(Blocked, "session_busy_or_unknown"):
            self.run_pilot(self.never_launch)
        self.go["adapter"] = "unknown"
        self.prepare()
        with self.assertRaisesRegex(Blocked, "approval_mismatch"):
            self.run_pilot(self.never_launch)

    def test_competing_requests_attach_to_one_launch(self):
        admitted, complete = Event(), Event()
        results = []

        def adapter(*args):
            admitted.set()
            self.assertTrue(complete.wait(5))
            return self.positive(*args)

        worker = Thread(target=lambda: results.append(self.run_pilot(adapter)), daemon=True)
        worker.start()
        try:
            self.assertTrue(admitted.wait(5))
            self.assertEqual("STARTING", self.run_pilot(self.never_launch)["status"])
        finally:
            complete.set()
            worker.join(6)
        self.assertEqual("REVIEW_READY", results[0]["status"])

    def test_crash_unknown_is_recoverable_only_without_launch(self):
        def broken(*_args):
            raise RuntimeError("simulated interruption")

        with self.assertRaisesRegex(RuntimeError, "interruption"):
            self.run_pilot(broken)
        self.args.reconcile = True
        self.clock_mock.return_value = self.clock + timedelta(hours=1)
        self.assertEqual("RECOVERY_REQUIRED", self.run_pilot(self.never_launch)["status"])
        with closing(sqlite3.connect(self.root / "pilot.sqlite3")) as db:
            self.assertEqual("RECOVERY_REQUIRED", db.execute("SELECT state FROM pilot").fetchone()[0])

    def test_bad_result_never_ready(self):
        def invalid(*_args):
            return "REVIEW_READY", "agent_completed", None, {"task": CARD["task"], "total": 38}

        result = self.run_pilot(invalid)
        self.assertEqual(("FAILED", "invalid_result"), (result["status"], result["reason"]))
        self.assertFalse(list(self.root.glob("run-*/result.json")))

    def test_written_outcome_survives_interrupted_finalization(self):
        with patch("runner.finish", side_effect=RuntimeError("interrupted receipt")):
            with self.assertRaisesRegex(RuntimeError, "interrupted receipt"):
                self.run_pilot(self.positive)
        self.args.reconcile = True
        self.assertEqual("REVIEW_READY", self.run_pilot(self.never_launch)["status"])
        self.assertEqual(1, len(list(self.root.glob("run-*/result.json"))))

    def test_git_workspace_has_exact_revision_no_ignored_files_or_remotes(self):
        env = {key: value for key, value in os.environ.items() if not key.upper().startswith("GIT_")}
        env.update(GIT_CONFIG_NOSYSTEM="1", GIT_CONFIG_GLOBAL=os.devnull)

        def git(*args):
            return subprocess.check_output(["git", "-C", str(self.work), *args], env=env)

        git("init")
        (self.work / ".gitignore").write_text("ignored.txt\n", encoding="utf-8")
        git("add", ".gitignore")
        git("-c", "user.name=Pilot test", "-c", "user.email=pilot@example.invalid", "commit", "-m", "fixture")
        base = git("rev-parse", "HEAD").decode().strip()
        clean_checkout(self.work, base)
        with self.assertRaisesRegex(Blocked, "workspace_sha_changed"):
            clean_checkout(self.work, "b" * 40)
        ignored = self.work / "ignored.txt"
        ignored.touch()
        with self.assertRaisesRegex(Blocked, "workspace_not_isolated_or_clean"):
            clean_checkout(self.work, base)
        ignored.unlink()
        git("remote", "add", "origin", "https://example.invalid/repository")
        with self.assertRaisesRegex(Blocked, "workspace_not_isolated_or_clean"):
            clean_checkout(self.work, base)

    def test_cli_protocol_and_restricted_environment(self):
        session = "12345678-1234-1234-1234-123456789abc"
        expected = {"task": CARD["task"], "total": 37}
        stdout = io.BytesIO(b"".join(encoded(x) for x in (
            {"type": "thread.started", "thread_id": session},
            {"type": "item.completed", "item": {"type": "agent_message", "text": json.dumps(expected)}},
            {"type": "turn.completed"},
        )))
        process = SimpleNamespace(stdout=stdout, poll=lambda: 0, wait=lambda timeout: 0)
        home = Path(self.go["codex_home"])
        scratch = self.root / "scratch"
        scratch.mkdir()
        events = []
        with patch("runner.subprocess.Popen", return_value=process) as spawn, patch.dict(os.environ, {"GITHUB_TOKEN": "synthetic"}):
            outcome = codex(Path(self.go["codex_cli"]), home, scratch, events.append, 10)
        self.assertEqual(("REVIEW_READY", "agent_completed", session, expected), outcome)
        self.assertEqual([session], events)
        self.assertIn("read-only", spawn.call_args.args[0])
        self.assertIn("shell_tool", spawn.call_args.args[0])
        self.assertNotIn("GITHUB_TOKEN", spawn.call_args.kwargs["env"])

    def test_cli_limit_reports_reason_without_raw_message(self):
        stdout = io.BytesIO(encoded({"type": "error", "message": "rate limit: private details"}))
        process = SimpleNamespace(stdout=stdout, poll=lambda: 1)
        scratch = self.root / "scratch"
        scratch.mkdir()
        with patch("runner.subprocess.Popen", return_value=process):
            outcome = codex(Path(self.go["codex_cli"]), Path(self.go["codex_home"]), scratch,
                            self.never_launch, 10)
        self.assertEqual(("FAILED", "model_limit", None, None), outcome)

    def test_cli_timeout_stops_spawned_process(self):
        stopped = Event()
        process = SimpleNamespace(stdout=io.BytesIO(), poll=lambda: 0 if stopped.is_set() else None,
                                  kill=stopped.set, wait=lambda timeout: 0)
        scratch = self.root / "scratch"
        scratch.mkdir()
        with patch("runner.subprocess.Popen", return_value=process):
            outcome = codex(Path(self.go["codex_cli"]), Path(self.go["codex_home"]), scratch,
                            self.never_launch, 0)
        self.assertEqual(("TIMED_OUT", "time_limit", None, None), outcome)
        self.assertTrue(stopped.is_set())


if __name__ == "__main__":
    unittest.main()
