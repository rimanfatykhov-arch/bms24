"""V1b GitHub provenance: real-shaped API fixtures, adversarial negative cases.

No network, tokens, production or release execution in these unit tests.
"""
from __future__ import annotations

import copy
from pathlib import Path
import sys
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parent))
from provenance_gate import PROFILE, ProvenanceHold, verify


HEAD = "3" * 40
CHECKOUT = "a" * 40
BASE = "f" * 40
RUNS = {"tiers": 44237304436, "full": 44237304478, "package": 44237304437}
ARTIFACT_ID = 14398405898


def fixture():
    req = {
        "schema": "bms24.release_operator.request.v0",
        "release_id": "BMS24-R1R2-20261119",
        "repository": "rimanfatykhov-arch/bms24",
        "environment": "production", "mode": "read_only_preflight",
        "production_baseline": {"source_sha": "9" * 40, "plugin_version": 2026111100},
        "candidate": {"pr_number": 124, "head_sha": HEAD, "ci_checkout_sha": CHECKOUT,
                      "plugin_version": 2026111601, "zip_sha256": "0" * 64,
                      "manifest_sha256": "1" * 64},
        "migration": {"required": True, "expected_new_tables": 5,
                      "recovery": "feature_off_forward_fix_or_separately_approved_restore"},
        "authorization": {"owner_approval": "NOT_GRANTED", "deployment_window": None},
    }
    plan = {
        "schema": "bms24.release_operator.ci_plan.v1b",
        "repository": "rimanfatykhov-arch/bms24",
        "pr_number": 124, "head_sha": HEAD, "checkout_sha": CHECKOUT, "base_sha": BASE,
        "profile": "R1R2-2026111601",
        "runs": {key: {"id": RUNS[key], "attempt": 1} for key in RUNS},
        "artifact": {"id": ARTIFACT_ID, "name": "release-install-44237304437-a1",
                     "workflow_run_id": 44237304437},
    }
    pr_ref = {"number": 124, "head": {"sha": HEAD}, "base": {"sha": BASE}}
    evidence = {
        "/branches/main": {"protected": True, "commit": {"sha": BASE}},
        "/pulls/124": {
            "number": 124, "state": "open", "draft": True,
            "merged": False, "merge_commit_sha": CHECKOUT,
            "head": {"sha": HEAD, "repo": {"full_name": "rimanfatykhov-arch/bms24"}},
            "base": {"sha": BASE, "repo": {"full_name": "rimanfatykhov-arch/bms24"}},
        },
        "/actions/runs/44237304437/artifacts?per_page=100": {
            "total_count": 1, "artifacts": [{
                "id": ARTIFACT_ID, "name": "release-install-44237304437-a1",
                "expired": False, "size_in_bytes": 3603390,
                "digest": "sha256:" + "4" * 64,
                "workflow_run": {"id": RUNS["package"], "head_sha": HEAD},
            }]
        }
    }
    job_id = 10000
    for key in ("tiers", "full", "package"):
        rid = RUNS[key]
        names = list(PROFILE[key]["jobs"]) + [x + "tag" for x in PROFILE[key]["job_prefixes"]]
        evidence["/actions/runs/{}".format(rid)] = {
            "id": rid, "path": PROFILE[key]["path"], "event": "pull_request",
            "head_sha": HEAD, "status": "completed", "conclusion": "success",
            "run_attempt": 1, "repository": {"full_name": "rimanfatykhov-arch/bms24"},
            "pull_requests": [pr_ref],
        }
        jobs = []
        for name in names:
            job_id += 1
            jobs.append({"name": name, "id": job_id, "run_id": rid,
                         "status": "completed", "conclusion": "success"})
        evidence["/actions/runs/{}/jobs?per_page=100".format(rid)] = {
            "total_count": len(jobs), "jobs": jobs
        }
    return req, plan, evidence


class ProvenanceGateTest(unittest.TestCase):
    def setUp(self):
        self.request, self.plan, self.evidence = fixture()

    def audit(self):
        return verify(self.request, self.plan, self.evidence.__getitem__)

    def reject(self):
        with self.assertRaises((ProvenanceHold, KeyError)):
            self.audit()

    def test_exact_required_evidence_is_hold_even_when_all_jobs_pass(self):
        d = self.audit()
        self.assertEqual(d["status"], "HOLD")
        self.assertFalse(d["deployable"])
        self.assertTrue(d["github_api_metadata_verified"])
        self.assertFalse(d["zip_contents_verified"])
        self.assertFalse(d["server_baseline_verified"])
        self.assertEqual(d["ci_completed_required_jobs"], 11)
        self.assertEqual(d["verified_artifact_id"], ARTIFACT_ID)
        self.assertTrue(d["pr_draft"])
        self.assertEqual(d["actions_executed"], [])

    def test_protected_main_must_equal_pinned_base(self):
        self.evidence["/branches/main"]["commit"]["sha"] = "8" * 40
        self.reject()
        self.request, self.plan, self.evidence = fixture()
        self.evidence["/branches/main"]["protected"] = False
        self.reject()

    def test_pr_head_base_checkout_and_fork_drift_rejected(self):
        for key in ("head", "base"):
            self.request, self.plan, self.evidence = fixture()
            self.evidence["/pulls/124"][key]["sha"] = "8" * 40
            self.reject()
        self.request, self.plan, self.evidence = fixture()
        self.evidence["/pulls/124"]["merge_commit_sha"] = "8" * 40
        self.reject()
        self.request, self.plan, self.evidence = fixture()
        self.evidence["/pulls/124"]["head"]["repo"]["full_name"] = "untrusted/fork"
        self.reject()

    def test_run_wrong_event_workflow_head_attempt_and_incomplete(self):
        rid = RUNS["package"]
        path = "/actions/runs/{}".format(rid)
        for prop, wrong in (
            ("event", "push"), ("path", ".github/workflows/unsafe.yml"),
            ("head_sha", "a" * 40), ("run_attempt", 2),
            ("status", "in_progress"), ("conclusion", "failure"),
        ):
            self.request, self.plan, self.evidence = fixture()
            self.evidence[path][prop] = wrong
            with self.subTest(prop=prop):
                self.reject()
        self.request, self.plan, self.evidence = fixture()
        self.evidence[path]["pull_requests"] = []
        self.reject()

    def test_required_ci_job_failure_skip_missing_duplicate_all_reject(self):
        rid = RUNS["tiers"]
        path = "/actions/runs/{}/jobs?per_page=100".format(rid)
        for failure in ("failure", "skipped", "cancelled", "neutral"):
            self.request, self.plan, self.evidence = fixture()
            self.evidence[path]["jobs"][2]["conclusion"] = failure
            with self.subTest(failure=failure):
                self.reject()
        self.request, self.plan, self.evidence = fixture()
        self.evidence[path]["jobs"].pop(1)
        self.evidence[path]["total_count"] -= 1
        self.reject()
        self.request, self.plan, self.evidence = fixture()
        self.evidence[path]["total_count"] = 101
        self.reject()
        self.request, self.plan, self.evidence = fixture()
        self.evidence[path]["jobs"][1]["name"] = self.evidence[path]["jobs"][0]["name"]
        self.reject()

    def test_missing_wrong_expired_or_stale_artifact_rejected(self):
        path = "/actions/runs/44237304437/artifacts?per_page=100"
        changes = [
            ("id", 21), ("name", "release-other"), ("expired", True),
            ("digest", "sha256:BAD"),
        ]
        for key, bad in changes:
            self.request, self.plan, self.evidence = fixture()
            self.evidence[path]["artifacts"][0][key] = bad
            with self.subTest(key=key):
                self.reject()
        self.request, self.plan, self.evidence = fixture()
        self.evidence[path]["artifacts"][0]["workflow_run"]["head_sha"] = "0" * 40
        self.reject()
        self.request, self.plan, self.evidence = fixture()
        self.evidence[path]["total_count"] = 200
        self.reject()

    def test_profile_and_plan_immutable_subject_rejected(self):
        for field, value in (
            ("profile", "FREEFORM"), ("base_sha", "invalid"),
            ("head_sha", "f" * 40), ("checkout_sha", "f" * 40),
            ("repository", "other/repo"), ("pr_number", 125),
        ):
            self.request, self.plan, self.evidence = fixture()
            self.plan[field] = value
            with self.subTest(field=field):
                self.reject()

    def test_altered_runs_and_artifact_not_allowed(self):
        for bad in (0, True, -1):
            self.request, self.plan, self.evidence = fixture()
            self.plan["runs"]["full"]["id"] = bad
            self.reject()
        self.request, self.plan, self.evidence = fixture()
        self.plan["runs"]["full"]["id"] = self.plan["runs"]["package"]["id"]
        self.reject()
        self.request, self.plan, self.evidence = fixture()
        self.plan["artifact"]["name"] = "release-else"
        self.reject()

    def test_malicious_extra_data_and_deployment_approval_rejected(self):
        self.plan["ssh_password"] = "never"
        self.reject()
        self.request, self.plan, self.evidence = fixture()
        self.request["authorization"]["owner_approval"] = "GRANTED"
        self.reject()

    def test_only_exact_expected_ci_job_profiles_pass(self):
        rid = RUNS["package"]
        path = "/actions/runs/{}/jobs?per_page=100".format(rid)
        self.evidence[path]["jobs"][0]["name"] = "package (ignored)"
        self.reject()
        self.request, self.plan, self.evidence = fixture()
        self.evidence[path]["jobs"].append({"id": 999999, "run_id": rid, "name": "skip", "status": "completed", "conclusion": "success"})
        self.evidence[path]["total_count"] += 1
        self.reject()


if __name__ == "__main__":
    unittest.main()
