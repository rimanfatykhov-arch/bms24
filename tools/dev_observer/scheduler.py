#!/usr/bin/env python3
"""Plan development work offline from a BDO-1 snapshot and a verified local queue."""
import argparse
from collections import Counter
from datetime import timedelta
from graphlib import CycleError, TopologicalSorter
import json
from pathlib import Path
import re
import sys

from observer import read_json
from policy import ACTORS, IDENTITY, fresh, number, sha, timestamp


WEB = "https://github.com/rimanfatykhov-arch/bms24"
ACTIONS = {"continue_work", "independent_review", "owner_decision"}
PRIORITY_LIMIT = 1000
BRANCH = re.compile(r"[A-Za-z0-9][A-Za-z0-9_./-]{0,127}")
OBSERVER_REASONS = {"recorded_hold", "docs_sync_hold", "pr_evidence_missing_or_failing", "candidate_not_ready",
                    "source_changed_or_expired", "partial_snapshot", "assignment_unknown", "dependencies_unconfirmed",
                    "dependency_not_confirmed_complete", "completion_confirmed", "observed_preconditions_met",
                    "candidate_identity_or_state_unknown"}


def source_ref(value, issue):
    pattern = rf"{re.escape(WEB)}/issues/{issue}(?:#issuecomment-[1-9][0-9]*)?"
    if not isinstance(value, str) or not re.fullmatch(pattern, value):
        raise ValueError("source reference")
    return value


def index(rows, reject_duplicates=False):
    if not isinstance(rows, list) or len(rows) > 500:
        raise ValueError("invalid rows")
    indexed, duplicates = {}, set()
    for row in rows:
        if not isinstance(row, dict) or not (key := number(row.get("number"))):
            raise ValueError("invalid task number")
        if key in indexed:
            duplicates.add(key)
        indexed[key] = row
    if duplicates and reject_duplicates:
        raise ValueError("duplicate snapshot identity")
    return indexed, duplicates


def dependencies(row):
    deps = row.get("dependencies")
    if not isinstance(deps, list) or any(not number(n) for n in deps) or len(set(deps)) != len(deps):
        raise ValueError("invalid dependencies")
    return set(deps)


def cycle_nodes(graph):
    pending = graph.copy()
    found = set()
    while pending:
        try:
            TopologicalSorter(pending).prepare()
            break
        except CycleError as failure:
            nodes = set(failure.args[1])
            found.update(nodes)
            pending = {n: deps - nodes for n, deps in pending.items() if n not in nodes}
    return found


def build_plan(snapshot, queue, as_of):
    registry_ref = source_ref(queue.get("registry_ref"), 198)
    priorities_ref = source_ref(queue.get("priorities_ref"), 205)
    main = sha(snapshot.get("main"))
    plan = {"schema_version": 1, "as_of": as_of.isoformat(), "main": main,
            "registry_ref": registry_ref, "priorities_ref": priorities_ref,
            "diagnostics": [], "recommendations": [], "blocked": [], "owner_decisions": []}
    if not main or sha(queue.get("main")) != main:
        plan["diagnostics"].append("main_mismatch")
    if snapshot.get("complete") is not True or snapshot.get("errors") != []:
        plan["diagnostics"].append("partial_snapshot")
    if snapshot.get("consistent") is not True:
        plan["diagnostics"].append("inconsistent_snapshot")
    if snapshot.get("fresh") is not True or not fresh(snapshot.get("collected_at"), as_of, timedelta(hours=24)):
        plan["diagnostics"].append("stale_snapshot")
    if plan["diagnostics"]:
        return plan

    observations, _ = index(snapshot.get("observations"), reject_duplicates=True)
    findings, _ = index(snapshot.get("findings"), reject_duplicates=True)
    queue_rows, conflicts = index(queue.get("tasks"))
    if observations.keys() != findings.keys():
        raise ValueError("unpaired snapshot records")
    graph = {n: dependencies(row) | dependencies(findings.get(n, {"dependencies": []}))
             for n, row in queue_rows.items() if n not in conflicts}
    cycles = cycle_nodes({n: deps & graph.keys() for n, deps in graph.items()})
    eligible, completion_candidates = {}, {}
    sorted_rows = []
    for n, row in queue_rows.items():
        if n in conflicts:
            sorted_rows.append((PRIORITY_LIMIT, n, {}))
            continue
        priority = row.get("priority")
        if type(priority) is not int or not 0 <= priority <= PRIORITY_LIMIT:
            raise ValueError("invalid priority")
        sorted_rows.append((priority, n, row))

    for priority, n, row in sorted(sorted_rows):
        observed, finding = observations.get(n, {}), findings.get(n, {})
        kind = observed.get("kind")
        identity = {key: sha(observed.get(key)) for key in IDENTITY}
        actor = row.get("executor")
        action = row.get("action")
        reviewer = row.get("reviewer")
        branch = row.get("branch")
        deps = graph.get(n, set())
        observer_reason = finding.get("reason")
        if not isinstance(observer_reason, str) or observer_reason not in OBSERVER_REASONS:
            observer_reason = None
        recorded_hold = (row.get("hold") is not False or observed.get("registry_status") == "HOLD"
                         or observer_reason == "recorded_hold")
        review_pending = (kind == "pr" and action == "independent_review" and observed.get("state") == "open"
                          and observed.get("mergeable") is True and observed.get("merged") is False
                          and finding.get("status") == "HOLD" and observer_reason == "pr_evidence_missing_or_failing"
                          and finding.get("ci") == "PASS" and finding.get("independent_review") == "NOT RUN")
        record = {"number": n, "kind": kind if kind in ("issue", "pr") else None,
                  "executor": actor if isinstance(actor, str) and actor in ACTORS else None,
                  **identity, "priority": priority, "dependencies": sorted(deps), "observer_reason": observer_reason,
                  "source": f"{WEB}/{'pull' if kind == 'pr' else 'issues'}/{n}"}
        reason = None
        if n in conflicts:
            reason = "assignment_conflict"
        elif not record["executor"] or actor != observed.get("executor") or actor != finding.get("executor"):
            reason = "assignment_unconfirmed"
        elif observed.get("changed") is True or kind not in ("issue", "pr") or finding.get("kind") != kind or (
                kind == "pr" and (not all(identity.values()) or identity["base"] != main)
                or kind == "issue" and identity["head"] != main) or any(
                    row.get(key) != value or finding.get(key) != value for key, value in identity.items()):
            reason = "identity_mismatch"
        elif recorded_hold:
            reason = "hold"
        elif finding.get("status") == "HOLD" and not review_pending:
            reason = observer_reason or "observer_not_ready"
        elif n in cycles:
            reason = "dependency_cycle"
        elif deps - findings.keys():
            reason = "missing_dependency"
        elif finding.get("status") == "COMPLETE" and finding.get("reason") == "completion_confirmed":
            completion_candidates[n] = (deps, record)
            continue
        elif not isinstance(action, str) or action not in ACTIONS or action == "owner_decision":
            reason = "owner_decision_required"
        elif action == "independent_review" and (not isinstance(reviewer, str) or reviewer not in ACTORS or reviewer == actor):
            reason = "independent_reviewer_unconfirmed"
        elif kind == "pr" and finding.get("independent_review") == "PASS":
            reason = "pr_review_already_passed"
        elif kind == "pr" and action == "independent_review" and not review_pending:
            reason = "pr_review_preconditions_unconfirmed"
        elif action == "continue_work" and (not isinstance(branch, str) or not BRANCH.fullmatch(branch)
                or branch.endswith(("/", ".", ".lock")) or ".." in branch or "//" in branch
                or branch.casefold() in {"main", "master"}):
            reason = "branch_unconfirmed"
        elif finding.get("status") != "READY" and not review_pending:
            reason = "observer_not_ready"
        if reason:
            record["reason"] = reason
            plan["blocked"].append(record)
            if reason in {"assignment_conflict", "assignment_unconfirmed", "hold", "owner_decision_required",
                          "independent_reviewer_unconfirmed"}:
                plan["owner_decisions"].append(record)
        else:
            eligible[n] = (record, action, branch, reviewer if action == "independent_review" else actor)

    completed = set()
    while completion_candidates:
        resolved = {n for n, (deps, _) in completion_candidates.items() if deps <= completed}
        if not resolved:
            break
        completed.update(resolved)
        for n in resolved:
            del completion_candidates[n]
    for _, record in completion_candidates.values():
        plan["blocked"].append(dict(record, reason="dependency_not_complete"))

    writers = Counter()
    for record, action, branch, _ in eligible.values():
        if set(record["dependencies"]) - completed:
            continue
        if action == "continue_work":
            writers[branch.casefold()] += 1
    for record, action, branch, actor in eligible.values():
        if set(record["dependencies"]) - completed:
            plan["blocked"].append(dict(record, reason="dependency_not_complete"))
        elif action == "continue_work" and writers[branch.casefold()] > 1:
            blocked = dict(record, reason="branch_conflict")
            plan["blocked"].append(blocked)
            plan["owner_decisions"].append(blocked)
        else:
            plan["recommendations"].append(dict(record, action=action, actor=actor))
    for key in ("blocked", "owner_decisions"):
        plan[key].sort(key=lambda row: (row["priority"], row["number"]))
    return plan


def render(plan):
    lines = ["# Development dry-run", "", f"As of `{plan['as_of']}` · main `{plan['main'] or 'unknown'}`", "",
             f"[Assignments]({plan['registry_ref']}) · [Priorities]({plan['priorities_ref']})"]
    if plan["diagnostics"]:
        lines += ["", "## Input diagnostics", "", *[f"- `{reason}`" for reason in plan["diagnostics"]]]
    for title, key in (("Recommendations", "recommendations"), ("Blocked", "blocked"),
                       ("Owner decisions", "owner_decisions")):
        lines += ["", f"## {title}", ""]
        for row in plan[key]:
            detail = row.get("action") or row["reason"]
            deps = ", ".join(f"#{n}" for n in row["dependencies"]) or "none"
            actor = row.get("actor") or row["executor"] or "unknown"
            lines.append(f"- [#{row['number']}]({row['source']}) · {actor} · "
                         f"`{row['head'] or 'unknown'}` · `{detail}` · prerequisites: {deps}")
        if not plan[key]:
            lines.append("None.")
    return "\n".join(lines) + "\n"


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--snapshot", required=True, type=Path)
    parser.add_argument("--queue", required=True, type=Path)
    parser.add_argument("--as-of", required=True)
    parser.add_argument("--out", required=True, type=Path)
    args = parser.parse_args(argv)
    as_of = timestamp(args.as_of)
    if as_of is None:
        parser.error("--as-of requires a UTC timestamp")
    try:
        plan = build_plan(read_json(args.snapshot), read_json(args.queue), as_of)
        entries = {"plan.json": json.dumps(plan, ensure_ascii=False, indent=2) + "\n", "summary.md": render(plan)}
        args.out.mkdir()
        created = []
        try:
            for name, content in entries.items():
                path = args.out / name
                with path.open("x", encoding="utf-8", newline="\n") as stream:
                    created.append(path)
                    stream.write(content)
        except OSError:
            for path in created:
                path.unlink()
            args.out.rmdir()
            raise
    except (OSError, ValueError, RecursionError):
        print("Scheduler error. Check the input contracts and use a new output directory.", file=sys.stderr)
        return 2
    return 1 if plan["diagnostics"] else 0


if __name__ == "__main__":
    raise SystemExit(main())
