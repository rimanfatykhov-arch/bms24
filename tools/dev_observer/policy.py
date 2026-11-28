"""Project safe evidence and derive Observer findings without executing it."""
from datetime import datetime, timedelta
import re

from github import ACTORS, ROUTE, WEB, choice, number, sha

TIERS = ".github/workflows/ci-tiers.yml"
PACKAGE = ".github/workflows/pr90-disposable.yml"
CORE = ".github/workflows/bms24-ci.yml"
FULL = {CORE, ".github/workflows/pr48-moodle-acceptance.yml",
        ".github/workflows/rc30-schema-repair.yml", ".github/workflows/rc30-corporate2613-repair.yml"}
WORKFLOWS = FULL | {TIERS, PACKAGE, ".github/workflows/rc30-corporate2613.yml"}
ENGINES = ("MariaDB 10.11.19", "Percona 8.0.46-37")
SAFE_JOBS = {
    "Plan targets", "Merge gate", "FAST / this-pr", "STANDARD / this-pr", "package", "rehearsal",
    "checks", "Retained PHP 7.4 syntax compatibility",
    *(f"BROWSER / this-pr / {x}" for x in ENGINES), *(f"Moodle 4.1.22 / {x}" for x in ENGINES),
}
CONCLUSIONS = {"success", "failure", "cancelled", "skipped", "timed_out", "neutral",
               "action_required", "stale", "startup_failure"}
IDENTITY = ("head", "tree", "base")
REASONS = {"endpoint_not_allowed", "query_not_allowed", "invalid_response", "api_denied", "api_error",
           "rate_limited", "api_unavailable", "invalid_page", "inconsistent_page", "incomplete_page",
           "page_limit", "invalid_object", "main_or_registry_changed", "item_limit", "run_limit"}
HEX256 = re.compile(r"[0-9a-f]{64}\Z")


def timestamp(value):
    try:
        t = datetime.fromisoformat(value.replace("Z", "+00:00"))
        return t if t.utcoffset() == timedelta(0) else None
    except (AttributeError, ValueError):
        return None


def fresh(value, current, window):
    t = timestamp(value)
    return t is not None and timedelta(0) <= current - t <= window


def obj(value):
    return value if isinstance(value, dict) else {}


def rows(value):
    return [item for item in value if isinstance(item, dict)] if isinstance(value, list) else []


def ids(value):
    return [x for x in value if number(x)] if isinstance(value, list) else []


def flag(value):
    return value if type(value) is bool else None


def digest(value):
    return value if isinstance(value, str) and HEX256.fullmatch(value) else None


def project_task(raw, main):
    n = number(raw.get("number"))
    kind = choice(raw.get("kind"), {"pr", "issue"})
    if not n or not kind:
        return None
    url = f"{WEB}/{'pull' if kind == 'pr' else 'issues'}/{n}"
    task = {"number": n, "kind": kind, "url": url, "executor": choice(raw.get("executor"), ACTORS),
            "registry_status": choice(raw.get("registry_status"), {"HOLD"}),
            "state": choice(raw.get("state"), {"open", "closed"}), "draft": flag(raw.get("draft")),
            "merged": flag(raw.get("merged")), "mergeable": flag(raw.get("mergeable")),
            "changed": raw.get("changed") is True,
            "comments": [{"id": x, "url": f"{url}#issuecomment-{x}"} for x in ids(raw.get("comments"))],
            "reviews": [], "runs": []}
    for name in IDENTITY:
        task[name] = sha(raw.get(name)) if kind == "pr" else (main if name == "head" else None)
    for review in rows(raw.get("reviews")):
        if rid := number(review.get("id")):
            task["reviews"].append({"id": rid, "url": f"{url}#pullrequestreview-{rid}",
                                    "head": sha(review.get("head")), "state": choice(review.get("state"),
                                    {"APPROVED", "CHANGES_REQUESTED", "COMMENTED", "DISMISSED", "PENDING"})})
    for run in rows(raw.get("runs")):
        rid, attempt = number(run.get("id")), number(run.get("attempt"))
        if not rid or not attempt:
            continue
        run_url = f"{WEB}/actions/runs/{rid}"
        task["runs"].append({
            "id": rid, "attempt": attempt, "url": f"{run_url}/attempts/{attempt}",
            "workflow": choice(run.get("workflow"), WORKFLOWS), "head": sha(run.get("head")),
            "base": sha(run.get("base")), "pr": number(run.get("pr")),
            "conclusion": choice(run.get("conclusion"), CONCLUSIONS),
            "jobs": [{"id": j["id"], "url": f"{run_url}/job/{j['id']}", "name": choice(j.get("name"), SAFE_JOBS),
                      "conclusion": choice(j.get("conclusion"), CONCLUSIONS)}
                     for j in rows(run.get("jobs")) if number(j.get("id"))],
            "artifacts": [{"id": a["id"], "url": f"{run_url}/artifacts/{a['id']}",
                           "expired": flag(a.get("expired")), "package": flag(a.get("package"))}
                          for a in rows(run.get("artifacts")) if number(a.get("id"))],
        })
    return task


def same_identity(evidence, task):
    keys = IDENTITY if task["kind"] == "pr" else ("head",)
    return all(task[key] is not None and evidence.get(key) == task[key] for key in keys)


def ci_status(task, evidence):
    profile = choice(evidence.get("profile"), {"FAST", "STANDARD", "FULL"})
    required = {PACKAGE: {"package"}, TIERS: {"Plan targets", "FAST / this-pr", "Merge gate"}}
    if profile in {"STANDARD", "FULL"}:
        required[TIERS].add("STANDARD / this-pr")
    if profile == "FULL":
        required[TIERS].update(f"BROWSER / this-pr / {x}" for x in ENGINES)
        required.update({workflow: {"checks", "Retained PHP 7.4 syntax compatibility"}
                         if workflow == CORE else {f"Moodle 4.1.22 / {x}" for x in ENGINES}
                         for workflow in FULL})
    selected = {}
    for workflow, names in required.items():
        matching = [r for r in task["runs"] if r["workflow"] == workflow and r["pr"] == task["number"]
                    and r["head"] == task["head"] and r["base"] == task["base"]]
        if not matching:
            return ("STALE" if any(r["workflow"] == workflow and r["base"] is not None
                                   for r in task["runs"]) else "NOT RUN"), []
        run = max(matching, key=lambda r: (r["id"], r["attempt"]))
        selected[workflow] = run
        if run["conclusion"] not in {None, "success"}:
            return "FAIL", [run["url"]]
        for name in sorted(names):
            jobs = [j for j in run["jobs"] if j["name"] == name]
            if len(jobs) != 1 or jobs[0]["conclusion"] is None:
                return "NOT RUN", [run["url"]]
            if jobs[0]["conclusion"] != "success":
                return "FAIL", [jobs[0]["url"]]
        if run["conclusion"] is None:
            return "NOT RUN", [run["url"]]
    tier = selected[TIERS]
    if not profile or not evidence:
        return "UNKNOWN", [tier["url"]]
    if not same_identity(evidence, task) or (evidence.get("run"), evidence.get("attempt")) != (tier["id"], tier["attempt"]):
        return "STALE", [tier["url"]]
    decision = choice(evidence.get("decision"), {"PASS", "FAIL"})
    return decision or "UNKNOWN", [selected[w]["url"] for w in sorted(selected)]


def release_status(task, evidence, ci, independent):
    if not evidence:
        return "HOLD", "release_evidence_missing"
    gate = obj(evidence.get("package"))
    if not same_identity(gate, task):
        return "HOLD", "package_identity_missing_or_stale"
    manifest = obj(gate.get("manifest"))
    zipped = digest(gate.get("zip_sha256"))
    if (not zipped or not digest(gate.get("manifest_sha256")) or manifest.get("zip_sha256") != zipped
            or not same_identity(manifest, task) or not sha(gate.get("baseline"))
            or manifest.get("baseline") != gate.get("baseline")):
        return "HOLD", "zip_manifest_baseline_mismatch"
    package = [r for r in task["runs"] if r["workflow"] == PACKAGE and r["pr"] == task["number"]
               and r["head"] == task["head"] and r["base"] == task["base"]]
    latest = max(package, key=lambda r: (r["id"], r["attempt"]), default={})
    if ((latest.get("id"), latest.get("attempt")) != (gate.get("run"), gate.get("attempt"))
            or not any(a["id"] == number(gate.get("artifact")) and a["expired"] is False and a["package"] is True
                       for a in latest.get("artifacts", []))):
        return "HOLD", "artifact_missing"
    approval = obj(evidence.get("owner"))
    if ci != "PASS" or independent != "PASS":
        return "HOLD", "ci_or_review_missing"
    if not same_identity(approval, task) \
            or approval.get("zip_sha256") != zipped or approval.get("decision") != "APPROVED" \
            or not any(c["id"] == number(approval.get("comment")) for c in task["comments"]):
        return "HOLD", "owner_decision_missing"
    if evidence.get("release_checks") != "PASS":
        return "HOLD", "mandatory_release_checks_missing"
    receipt = obj(evidence.get("receipt"))
    if (not same_identity(receipt, task) or receipt.get("zip_sha256") != zipped
            or receipt.get("server_confirmed") is not True or receipt.get("result") != "PASS"
            or not any(c["id"] == number(receipt.get("comment")) for c in task["comments"])):
        return "HOLD", "server_receipt_missing"
    return ("VERIFIED", "server_receipt_and_smoke") if receipt.get("smoke") == "PASS" else ("PUBLISHED", "server_receipt")


def review_status(task, evidence):
    if not evidence:
        return "NOT RUN", []
    ref = number(evidence.get("review")) or number(evidence.get("comment"))
    matches = ([r for r in task["reviews"] if r["id"] == ref] if number(evidence.get("review"))
               else [c for c in task["comments"] if c["id"] == ref])
    sources = [m["url"] for m in matches]
    if not same_identity(evidence, task) or any("head" in m and m["head"] != task["head"] for m in matches):
        return "STALE", sources
    verdict = choice(evidence.get("verdict"), {"PASS", "FAIL"})
    reviewer = choice(evidence.get("reviewer"), ACTORS)
    if len(matches) != 1 or not verdict or not reviewer or not task["executor"] or reviewer == task["executor"] \
            or evidence.get("appointed") is not True or evidence.get("independent") is not True \
            or matches[0].get("state") in {"DISMISSED", "PENDING"}:
        return "UNKNOWN", sources
    return verdict, sources


def docs_status(docs):
    drive, github = digest(docs.get("drive_digest")), digest(docs.get("github_digest"))
    if not drive or not github or not number(docs.get("document_ref")):
        return "DOC-SYNC HOLD", "NOT RUN"
    if drive != github:
        return "DOC-SYNC HOLD", "DRIFT"
    return "PASS", "MATCH"


def classify(snapshot, inputs, current):
    main = sha(snapshot.get("main"))
    collected = timestamp(snapshot.get("collected_at"))
    tasks = [t for row in rows(snapshot.get("tasks")) if (t := project_task(row, main))]
    evidence = obj(inputs.get("tasks"))
    errors = [{"endpoint": e["endpoint"], "reason": e["reason"]} for e in rows(snapshot.get("errors"))
              if isinstance(e.get("endpoint"), str) and ROUTE.fullmatch(e["endpoint"])
              and choice(e.get("reason"), REASONS)]
    unique = len({t["number"] for t in tasks}) == len(tasks)
    complete = (snapshot.get("complete") is True and unique and isinstance(snapshot.get("tasks"), list)
                and len(tasks) == len(snapshot["tasks"]) and bool(main) and bool(collected) and not snapshot.get("errors"))
    fresh_snapshot = fresh(snapshot.get("collected_at"), current, timedelta(hours=24))
    records = {}
    statuses = {}
    for task in sorted(tasks, key=lambda t: t["number"]):
        n = task["number"]
        ev = obj(evidence.get(str(n)))
        ev_current = same_identity(ev, task) and fresh(ev.get("checked_at"), current, timedelta(hours=24))
        inputs_ci = obj(ev.get("gate")) if ev_current else {}
        review = obj(ev.get("review"))
        ci, ci_links = ci_status(task, inputs_ci) if task["kind"] == "pr" else ("NOT RUN", [])
        independent, review_links = review_status(task, review)
        if review and not ev_current and independent != "STALE":
            independent = "STALE"
        release, release_reason = release_status(task, obj(ev.get("release")) if ev_current else {}, ci, independent)
        docs, docs_detail = docs_status(obj(ev.get("docs")) if ev_current else {})
        dep_input = ev.get("dependencies")
        deps = ids(dep_input)
        requires_docs = ev.get("requires_docs") is True
        if not fresh_snapshot or task["changed"] or (task["base"] and task["base"] != main):
            status, reason, action = "STALE", "source_changed_or_expired", "Refresh candidate evidence."
        elif not complete:
            status, reason, action = "UNKNOWN", "partial_snapshot", "Complete source collection."
        elif not task["executor"]:
            status, reason, action = "BLOCKED", "assignment_unknown", "Confirm the assignment in #198."
        elif task["registry_status"] == "HOLD" or ev_current and ev.get("hold") is True:
            status, reason, action = "HOLD", "recorded_hold", "Obtain the recorded owner decision."
        elif task["state"] not in {"open", "closed"} or task["kind"] == "pr" and (
                not all(task[k] for k in IDENTITY) or task["draft"] is None or task["merged"] is None):
            status, reason, action = "UNKNOWN", "candidate_identity_or_state_unknown", "Collect the exact source identity."
        elif not ev_current or not isinstance(dep_input, list) or len(deps) != len(dep_input) \
                or type(ev.get("requires_docs")) is not bool:
            status, reason, action = "BLOCKED", "dependencies_unconfirmed", "Confirm dependencies and scope for this source."
        elif requires_docs and docs != "PASS":
            status, reason, action = "HOLD", "docs_sync_hold", "Compare the confirmed Drive and GitHub revisions."
        elif task["kind"] == "pr" and (ci != "PASS" or independent != "PASS" or task["mergeable"] is not True):
            status, reason, action = "HOLD", "pr_evidence_missing_or_failing", "Confirm current CI, independent review and mergeability."
        elif ((task["state"] != "open" and ev.get("completed") is not True)
              or task["draft"] is True or task["kind"] == "pr" and task["merged"] is True
              and ev.get("completed") is not True):
            status, reason, action = "HOLD", "candidate_not_ready", "Confirm candidate state."
        else:
            status, reason, action = "READY", "observed_preconditions_met", "Review the evidence for this task."
        if status in {"UNKNOWN", "STALE"}:
            ci = independent = status
            release, release_reason = "HOLD", "snapshot_unverified"
            docs, docs_detail = "DOC-SYNC HOLD", "NOT RUN"
        if status == "HOLD" and reason == "recorded_hold":
            release, release_reason = "HOLD", "recorded_hold"
        statuses[n] = status
        review_actor = choice(review.get("reviewer"), ACTORS) if independent in {"PASS", "FAIL"} else None
        release_ev = obj(ev.get("release")) if ev_current else {}
        cited_comments = {number(obj(release_ev.get(k)).get("comment")) for k in ("owner", "receipt")}
        release_links = [c["url"] for c in task["comments"] if c["id"] in cited_comments]
        artifact = number(obj(release_ev.get("package")).get("artifact"))
        release_links.extend(a["url"] for run in task["runs"] for a in run["artifacts"] if a["id"] == artifact)
        records[n] = {"number": n, "kind": task["kind"], "source": task["url"],
                      "sources": [task["url"], f"{WEB}/issues/198", *ci_links, *review_links, *release_links],
                      "collected_at": collected.isoformat() if collected else None,
                      **{k: task[k] for k in IDENTITY}, "executor": task["executor"], "status": status,
                      "reason": reason, "recommended_action": action, "dependencies": deps if ev_current else [],
                      "ci": ci, "independent_review": independent,
                      "verified_verdict": {"actor": review_actor, "verdict": independent,
                                           "sources": review_links} if review_actor else None,
                      "release": release, "release_reason": release_reason,
                      "docs": docs, "docs_detail": docs_detail}

    # A READY dependency is eligible work, not proof that the prerequisite is complete.
    remaining = {n for n, status in statuses.items() if status == "READY"}
    proven = set()
    while remaining:
        resolved = {n for n in remaining if set(records[n]["dependencies"]) <= proven}
        if not resolved:
            break
        remaining -= resolved
        for n in resolved:
            if evidence[str(n)].get("completed") is True:
                proven.add(n)
                records[n].update(status="COMPLETE", reason="completion_confirmed",
                                  recommended_action="Retain the evidence reference.")
    for n in remaining:
        records[n].update(status="BLOCKED", reason="dependency_not_confirmed_complete",
                          recommended_action="Confirm completion of the recorded prerequisite.")
    registry_at = timestamp(snapshot.get("registry_updated_at"))
    return {"schema_version": 1, "registry": {"id": 198, "url": f"{WEB}/issues/198",
                                                "updated_at": registry_at.isoformat() if registry_at else None},
            "mode": choice(snapshot.get("mode"), {"offline", "github"}) or "offline",
            "main": main, "collected_at": collected.isoformat() if collected else None,
            "complete": complete, "fresh": fresh_snapshot, "consistent": not any(t["changed"] for t in tasks), "errors": errors,
            "observations": tasks, "findings": list(records.values())}
