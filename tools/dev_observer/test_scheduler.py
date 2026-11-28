import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

from scheduler import WEB, build_plan, main, timestamp
from policy import classify


NOW = timestamp("2026-11-28T12:00:00Z")
MAIN = "a" * 40


class SchedulerTests(unittest.TestCase):
    def setUp(self):
        self.snapshot = dict(schema_version=1, main=MAIN, complete=True, fresh=True, consistent=True,
                             collected_at="2026-11-28T11:00:00Z", errors=[], observations=[], findings=[])
        self.queue = dict(schema_version=1, main=MAIN, registry_ref=f"{WEB}/issues/198#issuecomment-1",
                          priorities_ref=f"{WEB}/issues/205#issuecomment-2", tasks=[])

    def task(self, n, *, dependencies=(), status="READY", **overrides):
        identity = dict(head=f"{n:040x}", tree="b" * 40, base=MAIN)
        record = dict(number=n, kind="pr", executor="Codex", **identity)
        self.snapshot["observations"].append(dict(record, changed=False))
        reason = {"COMPLETE": "completion_confirmed", "HOLD": "recorded_hold"}.get(status, "test")
        self.snapshot["findings"].append(dict(record, status=status, dependencies=[], reason=reason))
        row = dict(number=n, executor="Codex", action="continue_work", hold=False,
                   branch=f"feat/{n}", priority=1, dependencies=list(dependencies), **identity)
        row.update(overrides)
        self.queue["tasks"].append(row)

    def plan(self):
        return build_plan(self.snapshot, self.queue, NOW)

    def blocked(self):
        return {row["number"]: row["reason"] for row in self.plan()["blocked"]}

    def test_order_is_stable_and_ready_is_not_completion(self):
        self.task(4)
        self.task(3, dependencies=[4])
        self.task(2)
        self.task(1, status="HOLD")
        plan = self.plan()
        self.assertEqual([2, 4], [row["number"] for row in plan["recommendations"]])
        self.assertEqual({1: "hold", 3: "dependency_not_complete"}, self.blocked())
        self.queue["tasks"].reverse()
        self.assertEqual(plan, self.plan())

    def test_verified_completion_and_new_dependency(self):
        self.task(1, status="COMPLETE", dependencies=[3])
        self.task(2, dependencies=[1])
        self.task(3, status="COMPLETE")
        self.assertEqual([2], [row["number"] for row in self.plan()["recommendations"]])
        self.snapshot["findings"][2]["status"] = "READY"
        self.assertEqual("dependency_not_complete", self.blocked()[2])

    def test_conflicting_assignments_and_missing_dependency_and_cycles(self):
        self.task(1, dependencies=[2])
        self.task(2, dependencies=[1])
        self.task(3, dependencies=[80])
        self.task(4, executor="unknown")
        self.task(5)
        self.queue["tasks"].append(dict(self.queue["tasks"][-1], executor="Claude"))
        self.assertEqual({1: "dependency_cycle", 2: "dependency_cycle", 3: "missing_dependency",
                          4: "assignment_unconfirmed", 5: "assignment_conflict"}, self.blocked())

    def test_identity_action_and_branch_conflicts(self):
        self.task(1, branch="feat/shared")
        self.task(2, branch="feat/Shared")
        self.task(3, action="merge")
        self.task(4, head="c" * 40)
        self.assertEqual({1: "branch_conflict", 2: "branch_conflict", 3: "owner_decision_required",
                          4: "identity_mismatch"}, self.blocked())

    def test_rejects_partial_stale_and_changed_main(self):
        self.task(1)
        self.snapshot.update(complete=False, consistent=False, collected_at="2026-11-26T00:00:00Z")
        self.queue["main"] = "c" * 40
        plan = self.plan()
        self.assertEqual(["main_mismatch", "partial_snapshot", "inconsistent_snapshot", "stale_snapshot"],
                         plan["diagnostics"])
        self.assertEqual([], plan["recommendations"])

    def test_independent_review_uses_a_separately_assigned_actor(self):
        self.task(1, action="independent_review", reviewer="Codex")
        self.task(2, action="independent_review", reviewer="Claude")
        for observation in self.snapshot["observations"]:
            observation.update(state="open", mergeable=True, merged=False)
        for finding in self.snapshot["findings"]:
            finding.update(status="HOLD", reason="pr_evidence_missing_or_failing",
                           ci="PASS", independent_review="NOT RUN")
        self.assertEqual({1: "independent_reviewer_unconfirmed"}, self.blocked())
        self.assertEqual("Claude", self.plan()["recommendations"][0]["actor"])

    def test_observer_output_distinguishes_pending_review_from_release_hold(self):
        bundle = json.loads(Path(__file__).with_name("fixture.json").read_text(encoding="utf-8"))
        raw = bundle["snapshot"]
        original = classify(raw, bundle["evidence"], NOW)
        observations = {row["number"]: row for row in original["observations"]}
        self.queue["main"] = original["main"]
        for n in (14, 17):
            source = observations[n]
            self.queue["tasks"].append(dict(number=n, executor=source["executor"], action="independent_review",
                                           reviewer="Codex", hold=False, priority=1, dependencies=[],
                                           head=source["head"], tree=source["tree"], base=source["base"]))
        self.snapshot = original
        self.assertEqual("pr_review_already_passed", self.blocked()[17])
        self.assertEqual("docs_sync_hold", self.blocked()[14])
        del bundle["evidence"]["tasks"]["17"]["review"]
        self.snapshot = classify(raw, bundle["evidence"], NOW)
        plan = self.plan()
        self.assertEqual([17], [row["number"] for row in plan["recommendations"]])
        self.assertEqual("pr_evidence_missing_or_failing", plan["recommendations"][0]["observer_reason"])
        self.assertEqual([], plan["owner_decisions"])
        self.queue["tasks"][1]["hold"] = True
        self.assertEqual("hold", self.blocked()[17])

    def test_cli_has_no_network_or_process_calls_and_does_not_overwrite(self):
        self.task(1)
        with tempfile.TemporaryDirectory() as tmp, patch("socket.create_connection", side_effect=AssertionError), \
                patch("subprocess.Popen", side_effect=AssertionError):
            root = Path(tmp)
            snapshot, queue, output = (root / n for n in ("snapshot.json", "queue.json", "report"))
            snapshot.write_text(json.dumps(self.snapshot), encoding="utf-8")
            queue.write_text(json.dumps(self.queue), encoding="utf-8")
            args = ["--snapshot", str(snapshot), "--queue", str(queue), "--as-of", NOW.isoformat(), "--out", str(output)]
            self.assertEqual(0, main(args))
            previous = (output / "plan.json").read_bytes()
            self.assertEqual(2, main(args))
            self.assertEqual(previous, (output / "plan.json").read_bytes())
            self.snapshot["consistent"] = False
            snapshot.write_text(json.dumps(self.snapshot), encoding="utf-8")
            args[-1] = str(root / "diagnostic")
            self.assertEqual(1, main(args))
