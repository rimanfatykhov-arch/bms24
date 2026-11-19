#!/usr/bin/env python3
"""V1b: read-only GitHub provenance audit for an exact BMS24 CI release.

PR source code is never allowed to run with deployment credentials. This file
only reads GitHub API metadata and ALWAYS returns HOLD; it cannot deploy.
"""
from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
import re
import ssl
import urllib.error
import urllib.request

from artifact_gate import ArtifactHold, read_json
from preflight import InvalidRequest, validate

REPOSITORY = "rimanfatykhov-arch/bms24"
API = "https://api.github.com/repos/" + REPOSITORY
MAX_API_BYTES = 4 * 1024 * 1024
HEX40 = re.compile(r"[0-9a-f]{40}\Z")
HEX64 = re.compile(r"[0-9a-f]{64}\Z")
# Exact R1/R2 workflow identity, not an arbitrary set of green checks.
PROFILE = {
    "package": {
        "path": ".github/workflows/pr90-disposable.yml",
        "jobs": ("package",),
        "job_prefixes": ("rehearsal (mysqli, percona/percona-server:",
                         "rehearsal (mariadb, mariadb:"),
    },
    "full": {
        "path": ".github/workflows/pr48-moodle-acceptance.yml",
        "jobs": ("Moodle 4.1.22 / MariaDB 10.11.19",
                 "Moodle 4.1.22 / Percona 8.0.46-37"),
        "job_prefixes": (),
    },
    "tiers": {
        "path": ".github/workflows/ci-tiers.yml",
        "jobs": ("Plan targets", "FAST / this-pr", "STANDARD / this-pr",
                 "BROWSER / this-pr / MariaDB 10.11.19",
                 "BROWSER / this-pr / Percona 8.0.46-37", "Merge gate"),
        "job_prefixes": (),
    },
}


class ProvenanceHold(ValueError):
    pass


def require(ok: bool, reason: str) -> None:
    if not ok:
        raise ProvenanceHold(reason)


def exact(data, names: set[str], label: str):
    require(isinstance(data, dict) and set(data) == names, label + " has missing or extra keys")
    return data


def posint(value) -> bool:
    return type(value) is int and 0 < value <= 2**63 - 1


def sha40(value) -> bool:
    return isinstance(value, str) and HEX40.fullmatch(value) is not None


def require_success(data, label: str) -> None:
    require(data.get("status") == "completed" and data.get("conclusion") == "success",
            label + " not successful and completed")


def _ci_jobs(jobs_json, run_id: int, profile) -> dict:
    require(isinstance(jobs_json, dict), "invalid jobs response")
    jobs = jobs_json.get("jobs")
    total = jobs_json.get("total_count")
    require(isinstance(jobs, list) and type(total) is int and total == len(jobs) and
            0 < total <= 100, "incomplete jobs page: cannot authenticate full CI result")
    require(all(isinstance(j, dict) and posint(j.get("id")) and
                j.get("run_id") == run_id for j in jobs), "job identity mismatch")
    require(len({j["id"] for j in jobs}) == len(jobs), "duplicate CI job ID")
    for job in jobs:
        require_success(job, "CI job " + str(job.get("id")))
    seen = set()
    for expected in profile["jobs"]:
        matches = [j for j in jobs if j.get("name") == expected]
        require(len(matches) == 1, "required CI job missing/ambiguous: " + expected)
        seen.add(matches[0]["id"])
    for prefix in profile["job_prefixes"]:
        matches = [j for j in jobs if isinstance(j.get("name"), str) and j["name"].startswith(prefix)]
        require(len(matches) == 1, "required CI job missing/ambiguous: " + prefix)
        seen.add(matches[0]["id"])
    require(len(seen) == len(jobs), "unclassified CI job: verify changed workflow profile")
    return {"jobs_passed": len(jobs), "job_ids": sorted(seen)}


def _pr_match(pr, request, plan) -> None:
    require(isinstance(pr, dict) and pr.get("number") == plan["pr_number"],
            "GitHub PR number mismatch")
    require(pr.get("head", {}).get("sha") == request["candidate"]["head_sha"],
            "PR HEAD drift")
    require(pr.get("base", {}).get("sha") == plan["base_sha"], "PR base SHA drift")
    require(pr.get("merge_commit_sha") == request["candidate"]["ci_checkout_sha"],
            "PR merge checkout SHA drift")
    require(pr.get("base", {}).get("repo", {}).get("full_name") == REPOSITORY,
            "PR base repository mismatch")
    require(pr.get("head", {}).get("repo", {}).get("full_name") == REPOSITORY,
            "release PR from untrusted fork not accepted")
    require(pr.get("state") in ("open", "closed") and type(pr.get("draft")) is bool,
            "PR state missing")


def _run_match(run, rid: int, attempt: int, head: str, pr_number: int, base: str, profile) -> None:
    require(isinstance(run, dict) and run.get("id") == rid, "workflow run ID mismatch")
    require(run.get("path") == profile["path"], "wrong GitHub workflow path")
    require(run.get("event") == "pull_request", "non-PR event cannot attest release candidate")
    require(run.get("head_sha") == head and run.get("run_attempt") == attempt,
            "workflow source/attempt mismatch")
    require_success(run, "workflow " + str(rid))
    require(run.get("repository", {}).get("full_name") == REPOSITORY,
            "workflow repository mismatch")
    prs = run.get("pull_requests")
    require(isinstance(prs, list) and len(prs) > 0, "workflow PR association missing")
    require(any(isinstance(p, dict) and p.get("number") == pr_number and
                p.get("head", {}).get("sha") == head and
                p.get("base", {}).get("sha") == base for p in prs),
            "workflow does not belong to pinned PR/base/head")


def verify(request: dict, plan: dict, fetch) -> dict:
    """fetch(path) must GET from real api.github.com over verified TLS, never from PR-controlled JSON."""
    try:
        request = validate(request)
    except InvalidRequest as exc:
        raise ProvenanceHold("invalid release request: " + str(exc)) from exc
    p = exact(plan, {"schema", "repository", "pr_number", "head_sha", "checkout_sha",
                     "base_sha", "profile", "runs", "artifact"}, "plan")
    require(p["schema"] == "bms24.release_operator.ci_plan.v1b", "invalid CI plan schema")
    require(p["repository"] == REPOSITORY and p["profile"] == "R1R2-2026111601",
            "unknown CI profile or repository")
    require(p["pr_number"] == request["candidate"]["pr_number"] and
            p["head_sha"] == request["candidate"]["head_sha"] and
            p["checkout_sha"] == request["candidate"]["ci_checkout_sha"] and
            sha40(p["base_sha"]), "plan/request SHA mismatch")
    runs = exact(p["runs"], set(PROFILE), "runs")
    for key, val in runs.items():
        exact(val, {"id", "attempt"}, key + " run")
        require(posint(val["id"]) and posint(val["attempt"]) and val["attempt"] <= 100,
                "invalid run id/attempt")
    require(len({r["id"] for r in runs.values()}) == len(runs), "CI run IDs not unique")
    artifact = exact(p["artifact"], {"id", "name", "workflow_run_id"}, "artifact")
    require(posint(artifact["id"]) and
            artifact["workflow_run_id"] == runs["package"]["id"] and
            artifact["name"] == "release-install-{}-a{}".format(runs["package"]["id"],
                                                                 runs["package"]["attempt"]),
            "invalid immutable artifact pin")

    main = fetch("/branches/main")
    require(isinstance(main, dict) and main.get("protected") is True and
            main.get("commit", {}).get("sha") == p["base_sha"],
            "current protected main differs from pinned integration base")
    pr = fetch("/pulls/{}".format(p["pr_number"]))
    _pr_match(pr, request, p)
    checked = {}
    for key in ("tiers", "full", "package"):
        meta = runs[key]
        rid = meta["id"]
        workflow = fetch("/actions/runs/{}".format(rid))
        _run_match(workflow, rid, meta["attempt"], p["head_sha"],
                   p["pr_number"], p["base_sha"], PROFILE[key])
        jobs = fetch("/actions/runs/{}/jobs?per_page=100".format(rid))
        checked[key] = {"run_id": rid, "run_attempt": meta["attempt"],
                        "workflow": PROFILE[key]["path"],
                        **_ci_jobs(jobs, rid, PROFILE[key])}

    rid = runs["package"]["id"]
    artifacts = fetch("/actions/runs/{}/artifacts?per_page=100".format(rid))
    require(isinstance(artifacts, dict) and isinstance(artifacts.get("artifacts"), list) and
            type(artifacts.get("total_count")) is int and
            artifacts["total_count"] == len(artifacts["artifacts"]) <= 100,
            "incomplete artifact listing")
    selected = [a for a in artifacts["artifacts"] if isinstance(a, dict) and
                a.get("id") == artifact["id"]]
    require(len(selected) == 1, "pinned CI artifact missing or duplicated")
    a = selected[0]
    require(a.get("name") == artifact["name"] and a.get("expired") is False and
            posint(a.get("size_in_bytes")), "CI artifact name/availability mismatch")
    require(a.get("workflow_run", {}).get("id") == rid and
            a.get("workflow_run", {}).get("head_sha") == p["head_sha"],
            "CI artifact is not from pinned workflow/head")
    outer_digest = a.get("digest")
    require(isinstance(outer_digest, str) and outer_digest.startswith("sha256:") and
            HEX64.fullmatch(outer_digest[len("sha256:"):]) is not None,
            "GitHub Actions container artifact digest absent")
    return {
        "schema": "bms24.release_operator.provenance_report.v1b",
        "status": "HOLD", "deployable": False,
        "github_api_metadata_verified": True,
        "ci_completed_required_jobs": sum(c["jobs_passed"] for c in checked.values()),
        "verified_runs": checked,
        "verified_artifact_id": a["id"],
        "verified_artifact_name": a["name"],
        "github_actions_container_sha256": outer_digest[len("sha256:"):],
        "zip_contents_verified": False,
        "server_baseline_verified": False,
        "pr_draft": pr["draft"], "pr_merged": pr.get("merged") is True,
        "actions_executed": [],
        "blockers": [
            "GitHub API jobs are verified, but actual candidate ZIP and manifest bytes must pass V1a",
            "A CI artifact container digest is NOT the plugin ZIP SHA256",
            "No trusted production inventory, backup restore, migration executor or owner approval",
        ],
    }


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        raise ProvenanceHold("GitHub API redirected; refusing alternate origin")


class GitHubApi:
    """Read-only allowlisted GitHub API. Never use credentials in unreviewed PR jobs."""

    def __init__(self, token: str | None = None):
        self.token = token
        self.opener = urllib.request.build_opener(
            _NoRedirect(),
            urllib.request.HTTPSHandler(context=ssl.create_default_context()),
        )

    def __call__(self, path: str):
        if not isinstance(path, str) or not re.fullmatch(
            r"/(?:branches/main|pulls/[0-9]{1,12}|actions/runs/[0-9]{1,18}(?:/(?:jobs|artifacts)\?per_page=100)?)",
            path,
        ):
            raise ProvenanceHold("GitHub API endpoint is not allowlisted")
        headers = {"Accept": "application/vnd.github+json",
                   "X-GitHub-Api-Version": "2022-11-28",
                   "User-Agent": "BMS24-ReadOnlyReleaseEvidence/1.0"}
        if self.token:
            headers["Authorization"] = "Bearer " + self.token
        req = urllib.request.Request(API + path, headers=headers, method="GET")
        try:
            with self.opener.open(req, timeout=15) as response:
                if response.status != 200 or response.geturl() != API + path:
                    raise ProvenanceHold("unexpected GitHub API response")
                data = response.read(MAX_API_BYTES + 1)
                if len(data) > MAX_API_BYTES:
                    raise ProvenanceHold("GitHub API response over limit")
        except (urllib.error.URLError, TimeoutError, OSError) as exc:
            raise ProvenanceHold("GitHub API unavailable: no release attestation") from None
        try:
            result = json.loads(data.decode("utf-8"))
        except (ValueError, UnicodeError):
            raise ProvenanceHold("malformed GitHub API JSON") from None
        if not isinstance(result, dict):
            raise ProvenanceHold("GitHub API JSON is not an object")
        return result


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--request", type=Path, required=True)
    parser.add_argument("--plan", type=Path, required=True)
    parser.add_argument("--report", type=Path, required=True)
    args = parser.parse_args()
    try:
        request = read_json(args.request, 1024 * 1024)
        plan = read_json(args.plan, 1024 * 1024)
        # Use only in a trusted, code-reviewed execution environment. Never
        # expose this token to pull_request, fork, or arbitrary source jobs.
        fetcher = GitHubApi(os.environ.get("BMS_RELEASE_GITHUB_READ_TOKEN"))
        report = verify(request, plan, fetcher)
        status = 0
    except (ProvenanceHold, ArtifactHold, OSError, TypeError, KeyError) as exc:
        report = {"schema": "bms24.release_operator.provenance_report.v1b",
                  "status": "REJECTED", "deployable": False,
                  "reason": str(exc)[:200], "actions_executed": []}
        status = 2
    args.report.parent.mkdir(parents=True, exist_ok=True)
    args.report.write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n",
                           encoding="utf-8")
    print("BMS24 GitHub provenance: {} (NO DEPLOYMENT)".format(report["status"]))
    return status


if __name__ == "__main__":
    raise SystemExit(main())
