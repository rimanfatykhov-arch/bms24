#!/usr/bin/env python3
"""Safety and lifecycle tests for the offline Dev AI worktree/review gate."""
from __future__ import annotations

import copy
import importlib.util
import json
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("dev_ai_cycle", ROOT / "tools/dev_ai_cycle.py")
cycle = importlib.util.module_from_spec(spec)
spec.loader.exec_module(cycle)


def cmd(root, *args):
    return subprocess.run(["git", "-C", str(root), *args], stdout=subprocess.PIPE,
                          stderr=subprocess.PIPE, check=True).stdout.decode().strip()


def task():
    return {
        "schema_version": 1, "id": "BMS-1873", "title": "Improve UX",
        "product_goal": "Make task error messages clearer", "developer_id": "developer-01",
        "status": "approved", "approval": {"approved_by": "product-owner", "reference": "signed-offline-approval"},
        "risk": "low", "base_branch": "main",
        "allowed_paths": ["local/kbacompany/tests/"],
        "acceptance_criteria": ["The isolated tests are successful"]
    }


def review(role, inspector, identity):
    return {"schema_version": 1, "task_id": inspector["task_id"], "role": role,
            "reviewer_id": identity, "result": "PASS", "head_sha": inspector["head_sha"],
            "change_sha256": inspector["change_sha256"], "evidence": ["php -l: completed"]}


class Validation(unittest.TestCase):
    def checkbad(self, edit):
        with tempfile.TemporaryDirectory() as td:
            value = task()
            edit(value)
            p = Path(td) / "task.json"
            p.write_text(json.dumps(value))
            with self.assertRaises(cycle.Blocked):
                cycle.load_task(p)

    def test_valid_task(self):
        with tempfile.TemporaryDirectory() as td:
            p = Path(td) / "task.json"
            p.write_text(json.dumps(task()))
            self.assertEqual(cycle.load_task(p)["id"], "BMS-1873")

    def test_draft_task_valid_but_cannot_start(self):
        with tempfile.TemporaryDirectory() as td:
            t = task()
            t["status"] = "draft"
            t["approval"] = None
            p = Path(td) / "task.json"
            p.write_text(json.dumps(t))
            self.assertEqual(cycle.load_task(p)["status"], "draft")
            with self.assertRaises(cycle.Blocked):
                cycle.start(t, Path(td), Path(td) / "worktree")

    def test_duplicate_json_keys_rejected(self):
        with tempfile.TemporaryDirectory() as td:
            p = Path(td) / "task.json"
            p.write_text('{"id":"BMS-1873","id":"BMS-1874"}')
            with self.assertRaises(cycle.Blocked):
                cycle.load_task(p)

    def test_approval_required(self):
        self.checkbad(lambda t: t.update(approval=None))

    def test_dangerous_scope_rejected(self):
        self.checkbad(lambda t: t.update(allowed_paths=["local/kbacompany/db/"]))

    def test_tools_scope_rejected(self):
        self.checkbad(lambda t: t.update(allowed_paths=["tools/"]))

    def test_path_traversal_rejected(self):
        self.checkbad(lambda t: t.update(allowed_paths=["local/kbacompany/../tools/"]))

    def test_missing_criteria_rejected(self):
        self.checkbad(lambda t: t.update(acceptance_criteria=[]))

    def test_invalid_risk_rejected(self):
        self.checkbad(lambda t: t.update(risk="critical"))

    def test_bad_branch_rejected(self):
        self.checkbad(lambda t: t.update(base_branch="production"))

    def test_protected_prefix_safety(self):
        self.assertTrue(cycle.is_protected("local/kbacompany/cli/check_approval_queue_contract.py"))
        self.assertTrue(cycle.is_protected("local/kbacompany/cli/test_rc30_gate.py"))
        self.assertFalse(cycle.path_ok("local/kbacompany/version.php"))
        self.assertTrue(cycle.path_ok("local/kbacompany/tests/example_test.php"))
        self.assertFalse(cycle.path_ok("local/kbacompany/tests/../../version.php"))


class Lifecycle(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.repo = self.root / "repo"
        self.repo.mkdir()
        subprocess.run(["git", "init", "-q", "-b", "main", str(self.repo)], check=True)
        cmd(self.repo, "config", "user.name", "dev test")
        cmd(self.repo, "config", "user.email", "dev-test@example.invalid")
        (self.repo / "local/kbacompany/tests").mkdir(parents=True)
        (self.repo / "local/kbacompany/version.php").write_text("baseline")
        (self.repo / "local/kbacompany/tests/original.php").write_text("orig")
        cmd(self.repo, "add", ".")
        cmd(self.repo, "commit", "-qm", "baseline")
        self.t = task()
        self.wt = self.root / "worktree"

    def prepare(self):
        info = cycle.start(self.t, self.repo, self.wt)
        self.assertEqual(info["status"], "WORKTREE_CREATED")
        (self.wt / "local/kbacompany/tests/original.php").write_text("change")
        return cycle.inspect(self.t, self.wt)

    def test_creates_isolated_branch(self):
        report = self.prepare()
        self.assertEqual(report["status"], "REVIEW_REQUIRED")
        self.assertEqual(cmd(self.repo, "branch", "--show-current"), "main")
        self.assertEqual(cmd(self.wt, "branch", "--show-current"), "devai/BMS-1873")
        self.assertFalse(report["human_release_approval"])

    def test_good_reviews_do_not_approve_production(self):
        report = self.prepare()
        decision = cycle.verify_reviews(self.t, report, review("qa", report, "qa-01"),
                                        review("security", report, "sec-01"))
        self.assertEqual(decision["status"], "CANDIDATE_FOR_HUMAN_REVIEW")
        self.assertFalse(decision["human_release_approval"])

    def test_same_reviewer_rejected(self):
        r = self.prepare()
        with self.assertRaises(cycle.Blocked):
            cycle.verify_reviews(self.t, r, review("qa", r, "qa-01"), review("security", r, "qa-01"))

    def test_developer_self_review_rejected(self):
        r = self.prepare()
        with self.assertRaises(cycle.Blocked):
            cycle.verify_reviews(self.t, r, review("qa", r, "developer-01"), review("security", r, "sec-01"))

    def test_stale_review_rejected(self):
        r = self.prepare()
        old = review("qa", r, "qa-01")
        old["change_sha256"] = "0" * 64
        with self.assertRaises(cycle.Blocked):
            cycle.verify_reviews(self.t, r, old, review("security", r, "sec-01"))

    def test_review_failure_rejected(self):
        r = self.prepare()
        old = review("qa", r, "qa-01")
        old["result"] = "FAIL"
        with self.assertRaises(cycle.Blocked):
            cycle.verify_reviews(self.t, r, old, review("security", r, "sec-01"))

    def test_wrong_review_role_rejected(self):
        r = self.prepare()
        with self.assertRaises(cycle.Blocked):
            cycle.verify_reviews(self.t, r, review("qa", r, "qa-01"), review("qa", r, "sec-01"))

    def test_mutation_changes_snapshot_hash(self):
        r = self.prepare()
        (self.wt / "local/kbacompany/tests/original.php").write_text("newer")
        new = cycle.inspect(self.t, self.wt)
        self.assertNotEqual(r["change_sha256"], new["change_sha256"])

    def test_protected_file_modified_rejected(self):
        self.prepare()
        (self.wt / "local/kbacompany/version.php").write_text("wrong")
        with self.assertRaises(cycle.Blocked):
            cycle.inspect(self.t, self.wt)

    def test_out_of_scope_file_rejected(self):
        self.prepare()
        (self.wt / "local/kbacompany/other.php").write_text("wrong")
        with self.assertRaises(cycle.Blocked):
            cycle.inspect(self.t, self.wt)

    def test_unknown_untracked_files_checked(self):
        self.prepare()
        (self.wt / "local/kbacompany/config.php").write_text("not permitted")
        with self.assertRaises(cycle.Blocked):
            cycle.inspect(self.t, self.wt)

    def test_symlink_rejected(self):
        self.prepare()
        (self.wt / "local/kbacompany/tests/link").symlink_to(self.wt / "local/kbacompany/version.php")
        with self.assertRaises(cycle.Blocked):
            cycle.inspect(self.t, self.wt)

    def test_no_changes_rejected(self):
        cycle.start(self.t, self.repo, self.wt)
        with self.assertRaises(cycle.Blocked):
            cycle.inspect(self.t, self.wt)

    def test_start_dirty_repo_rejected(self):
        (self.repo / "local/kbacompany/version.php").write_text("dirty")
        with self.assertRaises(cycle.Blocked):
            cycle.start(self.t, self.repo, self.wt)

    def test_start_nested_dest_rejected(self):
        with self.assertRaises(cycle.Blocked):
            cycle.start(self.t, self.repo, self.repo / "a-worktree")

    def test_start_duplicate_branch_rejected(self):
        cycle.start(self.t, self.repo, self.wt)
        with self.assertRaises(cycle.Blocked):
            cycle.start(self.t, self.repo, self.root / "another")

    def test_text_output_does_not_leak_stack_trace(self):
        p = self.root / "bad.json"
        p.write_text("broken")
        run = subprocess.run(["python", str(ROOT / "tools/dev_ai_cycle.py"), "validate", "--task", str(p)],
                             stdout=subprocess.PIPE, check=False, text=True)
        self.assertEqual(run.returncode, 2)
        self.assertEqual(json.loads(run.stdout)["status"], "HOLD")

    def test_cli_verify_rejects_stale_inspection(self):
        r = self.prepare()
        review_dir = self.root / "reviews"
        review_dir.mkdir()
        taskfile = self.root / "task.json"
        taskfile.write_text(json.dumps(self.t))
        for name, content in [("inspection", r), ("qa", review("qa", r, "qa-01")),
                              ("security", review("security", r, "sec-01"))]:
            (review_dir / (name + ".json")).write_text(json.dumps(content))
        command = ["python", str(ROOT / "tools/dev_ai_cycle.py"), "verify", "--task",
                   str(taskfile), "--repo", str(self.wt),
                   "--inspection", str(review_dir / "inspection.json"), "--qa",
                   str(review_dir / "qa.json"), "--security", str(review_dir / "security.json")]
        good = subprocess.run(command, stdout=subprocess.PIPE, check=False, text=True)
        self.assertEqual(good.returncode, 0)
        self.assertEqual(json.loads(good.stdout)["status"], "CANDIDATE_FOR_HUMAN_REVIEW")
        (self.wt / "local/kbacompany/tests/original.php").write_text("post-review edit")
        bad = subprocess.run(command, stdout=subprocess.PIPE, check=False, text=True)
        self.assertEqual(bad.returncode, 2)
        self.assertEqual(json.loads(bad.stdout)["status"], "HOLD")

    def test_invalid_review_document_is_held(self):
        r = self.prepare()
        bad = review("qa", r, "qa-01")
        bad["evidence"] = []
        with self.assertRaises(cycle.Blocked):
            cycle.verify_reviews(self.t, r, bad, review("security", r, "sec-01"))

    def test_ignored_secret_in_worktree_rejected(self):
        self.prepare()
        (self.wt / ".gitignore").write_text("config.php\n")
        # Modification to protected .gitignore must be rejected, even without secret.
        with self.assertRaises(cycle.Blocked):
            cycle.inspect(self.t, self.wt)
        (self.wt / ".gitignore").unlink()
        (self.wt / "local/kbacompany/config.php").write_text("secret")
        with self.assertRaises(cycle.Blocked):
            cycle.inspect(self.t, self.wt)

    def test_zero_differences_after_revert_is_held(self):
        self.prepare()
        (self.wt / "local/kbacompany/tests/original.php").write_text("orig")
        with self.assertRaises(cycle.Blocked):
            cycle.inspect(self.t, self.wt)



if __name__ == "__main__":
    unittest.main(verbosity=2)
