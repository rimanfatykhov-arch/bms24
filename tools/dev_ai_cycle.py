#!/usr/bin/env python3
"""Offline, fail-closed BMS Dev AI task/worktree/review contracts.

Never connects to a model, Moodle, a Git remote or a production server.
Review records are assertions, NOT authenticated signatures or deployment approval.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
TASK_ID = re.compile(r"BMS-[0-9A-Z]{3,24}\Z")
SAFE_PART = re.compile(r"[a-zA-Z0-9_./-]+\Z")
PROTECTED = (
    "AGENTS.md", "dev_ai/", "tools/", ".github/", ".gitignore",
    "local/kbacompany/version.php", "local/kbacompany/db/",
    "local/kbacompany/release/", "local/kbacompany/docs/BMS_DEV_AI_",
)
SAFE_SCOPES = ("local/kbacompany/", "docs/features/")
MAX_TASK_BYTES = 128 * 1024
MAX_REVIEW_BYTES = 128 * 1024


class Blocked(ValueError):
    pass


def json_file(path: Path, limit: int) -> dict:
    if not path.is_file() or path.stat().st_size > limit:
        raise Blocked("missing or oversized JSON record")

    def no_duplicates(pairs):
        obj = {}
        for key, value in pairs:
            if key in obj:
                raise Blocked("duplicate JSON key")
            obj[key] = value
        return obj

    try:
        content = json.loads(path.read_text(encoding="utf-8"), object_pairs_hook=no_duplicates)
    except (UnicodeError, json.JSONDecodeError) as exc:
        raise Blocked("invalid JSON record") from exc
    if not isinstance(content, dict):
        raise Blocked("record must be a JSON object")
    return content


def is_protected(path: str) -> bool:
    return (any(path == entry if not entry.endswith("/") else path.startswith(entry)
                for entry in PROTECTED) or
            path.startswith("local/kbacompany/cli/check_") or
            path.startswith("local/kbacompany/cli/test_"))


def path_ok(path: str) -> bool:
    name = path.rsplit("/", 1)[-1]
    if (name in {"config.php", ".env", "id_rsa", "id_ed25519"} or
            name.startswith(".env.") or "moodledata" in path.split("/")):
        return False
    if (not path or not SAFE_PART.fullmatch(path) or path.startswith("/") or
            ".." in path.split("/") or "." in path.split("/") or "//" in path):
        return False
    return any(path.startswith(p) for p in SAFE_SCOPES) and not is_protected(path)


def load_task(path: Path) -> dict:
    data = json_file(path, MAX_TASK_BYTES)
    if data.get("schema_version") != 1:
        raise Blocked("task schema_version must equal 1")
    task_id = data.get("id")
    if not isinstance(task_id, str) or not TASK_ID.fullmatch(task_id):
        raise Blocked("invalid task id")
    for key in ("title", "product_goal", "developer_id"):
        if not isinstance(data.get(key), str) or not data[key].strip() or len(data[key]) > 2000:
            raise Blocked("invalid task field: " + key)
    if not re.fullmatch(r"[a-zA-Z0-9_-]{2,64}", data["developer_id"]):
        raise Blocked("invalid developer id")
    if data.get("status") not in ("draft", "approved"):
        raise Blocked("invalid task status")
    if data.get("risk") not in ("low", "medium", "high"):
        raise Blocked("invalid risk category")
    if data.get("base_branch") != "main":
        raise Blocked("only main is an acceptable baseline")
    criteria = data.get("acceptance_criteria")
    if (not isinstance(criteria, list) or not 1 <= len(criteria) <= 25 or
            any(not isinstance(x, str) or not x.strip() or len(x) > 1000 for x in criteria)):
        raise Blocked("acceptance criteria are required")
    paths = data.get("allowed_paths")
    if (not isinstance(paths, list) or not 1 <= len(paths) <= 20 or
            any(not isinstance(x, str) or not x.endswith("/") or
                not path_ok(x + "agent-owned-file") for x in paths) or
            len(paths) != len(set(paths))):
        raise Blocked("invalid allowed_paths: only explicitly scoped unprotected directories")
    if data["status"] == "approved":
        approval = data.get("approval")
        if (not isinstance(approval, dict) or not isinstance(approval.get("approved_by"), str)
                or not approval["approved_by"].strip() or
                not isinstance(approval.get("reference"), str) or not approval["reference"].strip()):
            raise Blocked("approved tasks need a recorded human decision reference")
    return data


def git(repo: Path, *args: str) -> bytes:
    p = subprocess.run(["git", "-C", str(repo), *args], stdout=subprocess.PIPE,
                       stderr=subprocess.PIPE, check=False, timeout=20)
    if p.returncode:
        raise Blocked("Git operation failed: check repository, refs and permissions")
    return p.stdout


def branch_for(task: dict) -> str:
    return "devai/" + task["id"]


def start(task: dict, repo: Path, destination: Path) -> dict:
    if task["status"] != "approved":
        raise Blocked("task must be approved before creating worktree")
    repo = repo.resolve(strict=True)
    if git(repo, "status", "--porcelain", "--untracked-files=normal").strip():
        raise Blocked("source repository must be clean")
    if git(repo, "branch", "--show-current").decode().strip() != "main":
        raise Blocked("start from main worktree only")
    if not git(repo, "rev-parse", "--verify", "main^{commit}").strip():
        raise Blocked("baseline main is missing")
    destination = destination.absolute()
    if destination.exists() or destination == repo or repo in destination.parents:
        raise Blocked("worktree must be a new external directory")
    branch = branch_for(task)
    p = subprocess.run(["git", "-C", str(repo), "worktree", "add", "-b", branch,
                        str(destination), "main"], stdout=subprocess.PIPE,
                       stderr=subprocess.PIPE, check=False, timeout=30)
    if p.returncode:
        raise Blocked("worktree creation failed; branch or destination may already exist")
    return {"status": "WORKTREE_CREATED", "task_id": task["id"],
            "branch": branch, "destination": str(destination)}


def changed_paths(repo: Path, base: str) -> list[str]:
    tracked = git(repo, "diff", "--no-ext-diff", "--no-renames", "--name-only", "-z", base, "--")
    untracked = git(repo, "ls-files", "--others", "--exclude-standard", "-z")
    entries = [x.decode("utf-8", "strict") for x in (tracked + untracked).split(b"\0") if x]
    return sorted(set(entries))


def inspect(task: dict, repo: Path) -> dict:
    if task["status"] != "approved":
        raise Blocked("task must be approved before inspection")
    repo = repo.resolve(strict=True)
    branch = git(repo, "branch", "--show-current").decode().strip()
    if branch != branch_for(task):
        raise Blocked("changes must be in the assigned isolated branch")
    base = git(repo, "rev-parse", "main^{commit}").decode().strip()
    head = git(repo, "rev-parse", "HEAD^{commit}").decode().strip()
    git(repo, "merge-base", "--is-ancestor", base, head)
    # Git ignores credentials by design. Check them independently so that an
    # ignored but present config.php/.env cannot escape the scope gate.
    plugin = repo / "local/kbacompany"
    if plugin.is_dir():
        for existing in plugin.rglob("*"):
            if existing.is_symlink():
                raise Blocked("symlink present in plugin worktree")
            if existing.is_file() and not path_ok(existing.relative_to(repo).as_posix()):
                # Protected *tracked* files are normal, forbidden secret names are not.
                if (existing.name in {"config.php", ".env", "id_rsa", "id_ed25519"} or
                        existing.name.startswith(".env.")):
                    raise Blocked("sensitive file present in plugin worktree")
    changes = changed_paths(repo, base)
    if not changes:
        raise Blocked("no changes to review")
    if len(changes) > 200:
        raise Blocked("change scope exceeds 200 files")
    violations = [path for path in changes if not path_ok(path) or
                  not any(path.startswith(p) for p in task["allowed_paths"])]
    if violations:
        raise Blocked("changed files outside approved scope: " + ", ".join(violations[:10]))
    h = hashlib.sha256()
    for value in (task["id"], branch, base, head):
        h.update(value.encode() + b"\0")
    for path in changes:
        src = repo / path
        if src.is_symlink():
            raise Blocked("symlinks not permitted in task changes")
        if src.exists():
            if not src.is_file() or src.stat().st_size > 4 * 1024 * 1024:
                raise Blocked("non-regular or excessively large changed file")
            digest = hashlib.sha256(src.read_bytes()).hexdigest()
        else:
            digest = "DELETED"
        h.update(path.encode() + b"\0" + digest.encode() + b"\0")
    return {"status": "REVIEW_REQUIRED", "task_id": task["id"],
            "branch": branch, "base_sha": base, "head_sha": head,
            "change_sha256": h.hexdigest(), "changed_paths": changes,
            "approved_scope": task["allowed_paths"], "human_release_approval": False}


def verify_reviews(task: dict, inspection: dict, qa: dict, security: dict) -> dict:
    if inspection.get("status") != "REVIEW_REQUIRED" or inspection.get("task_id") != task["id"]:
        raise Blocked("invalid inspection evidence")
    reviewers = []
    for role, review in (("qa", qa), ("security", security)):
        if review.get("schema_version") != 1 or review.get("role") != role:
            raise Blocked("missing independent " + role + " review")
        if review.get("task_id") != task["id"] or review.get("result") != "PASS":
            raise Blocked(role + " review failed or mismatched task")
        if review.get("change_sha256") != inspection.get("change_sha256"):
            raise Blocked(role + " review is stale")
        if review.get("head_sha") != inspection.get("head_sha"):
            raise Blocked(role + " review is for another commit")
        if not isinstance(review.get("reviewer_id"), str) or not re.fullmatch(
                r"[a-zA-Z0-9_-]{2,64}", review["reviewer_id"]):
            raise Blocked("invalid reviewer identity")
        if not isinstance(review.get("evidence"), list) or not review["evidence"] or any(
                not isinstance(x, str) or not x.strip() or len(x) > 1000 for x in review["evidence"]):
            raise Blocked("review evidence missing")
        reviewers.append(review["reviewer_id"])
    if len(set([task["developer_id"], *reviewers])) != 3:
        raise Blocked("developer, QA and security reviewer must be distinct")
    return {"status": "CANDIDATE_FOR_HUMAN_REVIEW", "task_id": task["id"],
            "change_sha256": inspection["change_sha256"], "reviewers": {"qa": reviewers[0], "security": reviewers[1]},
            "human_release_approval": False,
            "warning": "Review files are not cryptographic attestations; authenticated PR approval and staging checks still required"}


def output(value: dict) -> None:
    print(json.dumps(value, ensure_ascii=False, sort_keys=True, indent=2))


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    subs = parser.add_subparsers(dest="command", required=True)
    for command in ("validate", "start", "inspect", "verify"):
        p = subs.add_parser(command)
        p.add_argument("--task", type=Path, required=True)
        if command in ("start", "inspect", "verify"):
            p.add_argument("--repo", type=Path, default=ROOT)
        if command == "start":
            p.add_argument("--destination", type=Path, required=True)
        if command == "verify":
            p.add_argument("--inspection", type=Path, required=True)
            p.add_argument("--qa", type=Path, required=True)
            p.add_argument("--security", type=Path, required=True)
    args = parser.parse_args()
    try:
        task = load_task(args.task)
        if args.command == "validate":
            value = {"status": "VALID", "task_id": task["id"], "stage": task["status"]}
        elif args.command == "start":
            value = start(task, args.repo, args.destination)
        elif args.command == "inspect":
            value = inspect(task, args.repo)
        else:
            current_inspection = inspect(task, args.repo)
            submitted_inspection = json_file(args.inspection, MAX_REVIEW_BYTES)
            if submitted_inspection != current_inspection:
                raise Blocked("inspection is stale or modified relative to current worktree")
            value = verify_reviews(task, current_inspection,
                                   json_file(args.qa, MAX_REVIEW_BYTES),
                                   json_file(args.security, MAX_REVIEW_BYTES))
        output(value)
        return 0
    except (Blocked, OSError, UnicodeError, subprocess.TimeoutExpired) as exc:
        output({"status": "HOLD", "reason": str(exc) if isinstance(exc, Blocked) else "unavailable workflow input"})
        return 2


if __name__ == "__main__":
    sys.exit(main())
