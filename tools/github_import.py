#!/usr/bin/env python3
"""Safely prepare a BMS24 source Git bundle as a GitHub import branch.

No credentials are managed. Default mode does NOT push; main is never updated.
Git source history is preserved as the second parent of an import commit.
"""
from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile

EXPECTED_VERSION = 2026101877
DEFAULT_BRANCH = "import/build2026101877"
SOURCE_REF = "refs/heads/main"
MAX_SOURCE_FILES = 20000


class ImportHold(Exception):
    pass


def git(where: Path | None, *args: str, env: dict | None = None) -> str:
    cmd = ["git"] + (["-C", str(where)] if where is not None else []) + list(args)
    result = subprocess.run(cmd, capture_output=True, text=True, encoding="utf-8", errors="replace", env=env, check=False)
    if result.returncode:
        # Do not surface stdout/stderr: remote URLs can embed credentials and
        # Git's exception text can include values from environment/credential helpers.
        raise ImportHold("git operation failed: " + args[0])
    return result.stdout.strip()


def version_from_commit(repo: Path, commit: str) -> int:
    data = git(repo, "show", commit + ":local/kbacompany/version.php")
    match = re.search(r"\$plugin->version\s*=\s*(\d+)\s*;", data)
    if match is None:
        raise ImportHold("missing Moodle plugin version in imported tree")
    return int(match.group(1))


def verify_tree(repo: Path, source_sha: str) -> int:
    names = git(repo, "ls-tree", "-r", "--name-only", source_sha).splitlines()
    if not names or len(names) > MAX_SOURCE_FILES:
        raise ImportHold("source tree size outside limits")
    for path in names:
        parts = path.lower().split("/")
        name = parts[-1]
        if (".." in parts or any(not p for p in parts) or
                name in {"config.php", ".env", "id_rsa", "id_ed25519"} or
                name.startswith(".env.") or "moodledata" in parts or
                name.endswith((".pem", ".p12", ".key")) or
                name.endswith((".sql", ".sql.gz", ".sqlite"))):
            raise ImportHold("source contains a prohibited tracked path")
    raw = git(repo, "ls-tree", "-r", source_sha)
    if any(line.startswith("120000 ") for line in raw.splitlines()):
        raise ImportHold("source contains symlinks")
    for required in ("AGENTS.md", "tools/dev_ai_gate.py", "local/kbacompany/db/upgrade.php"):
        if required not in names:
            raise ImportHold("source is missing required development files")
    return len(names)


def run(bundle: Path, remote: str, branch: str, *, push: bool = False) -> dict:
    if not bundle.is_file():
        raise ImportHold("Git bundle not found")
    if not re.fullmatch(r"import/build[0-9]{10}", branch):
        raise ImportHold("branch must be a versioned import/* branch")
    if branch != DEFAULT_BRANCH:
        raise ImportHold("branch must match current build")
    if not remote or remote.startswith("-") or "\n" in remote:
        raise ImportHold("invalid Git remote")
    # Avoid embedding credentials in the remote URL or diagnostics.
    if re.search(r"https?://[^/]*@", remote):
        raise ImportHold("credential-bearing remote URLs are prohibited")
    with tempfile.TemporaryDirectory(prefix="bms24-git-import-") as tmp:
        local = Path(tmp) / "repo"
        git(None, "clone", "--quiet", "--no-checkout", "--", remote, str(local))
        git(local, "fetch", "--quiet", str(bundle.resolve()), SOURCE_REF + ":refs/heads/bms24-import-source")
        source_sha = git(local, "rev-parse", "refs/heads/bms24-import-source")
        if version_from_commit(local, source_sha) != EXPECTED_VERSION:
            raise ImportHold("bundle does not contain the expected BMS24 build")
        count = verify_tree(local, source_sha)
        # Only existing approved base branches; never modify origin/main.
        old_base = "refs/remotes/origin/import/build2026101876"
        exists = subprocess.run(["git", "-C", str(local), "show-ref", "--verify", "--quiet", old_base], check=False).returncode == 0
        base_ref = old_base if exists else "refs/remotes/origin/main"
        base_sha = git(local, "rev-parse", base_ref)
        target_ref = "refs/remotes/origin/" + branch
        target_exists = subprocess.run(["git", "-C", str(local), "show-ref", "--verify", "--quiet", target_ref], check=False).returncode == 0
        if target_exists:
            raise ImportHold("target import branch already exists; refusing to overwrite")
        tree_sha = git(local, "rev-parse", source_sha + "^{tree}")
        date = git(local, "show", "-s", "--format=%cI", source_sha)
        environ = dict(os.environ, GIT_AUTHOR_NAME="BMS Dev AI Import", GIT_COMMITTER_NAME="BMS Dev AI Import",
                       GIT_AUTHOR_EMAIL="bms-dev-ai@users.noreply.github.com", GIT_COMMITTER_EMAIL="bms-dev-ai@users.noreply.github.com",
                       GIT_AUTHOR_DATE=date, GIT_COMMITTER_DATE=date)
        # git commit-tree requires a message via -m. Two-parent commit retains
        # old GitHub history AND all source bundle commits without force push.
        commit_sha = git(local, "commit-tree", tree_sha, "-p", base_sha, "-p", source_sha,
                         "-m", "import: BMS24 build2026101877 full source (review required)", env=environ)
        git(local, "update-ref", "refs/heads/" + branch, commit_sha)
        out = {"status": "PREPARED", "build": EXPECTED_VERSION, "branch": branch,
               "base": base_sha, "source": source_sha, "commit": commit_sha,
               "source_files": count, "pushed": False, "main_unchanged": True}
        if push:
            git(local, "push", "origin", "refs/heads/" + branch + ":refs/heads/" + branch)
            out["pushed"] = True
            out["status"] = "PUSHED_FOR_REVIEW"
        return out


def main() -> None:
    parser = argparse.ArgumentParser(description="Safely stage BMS24 Git bundle (default: no push)")
    parser.add_argument("--bundle", type=Path, required=True)
    parser.add_argument("--remote", required=True, help="Git remote URL or local bare repo")
    parser.add_argument("--branch", default=DEFAULT_BRANCH)
    parser.add_argument("--push", action="store_true", help="Explicitly publish only the import branch; never main")
    opts = parser.parse_args()
    try:
        print(json.dumps(run(opts.bundle, opts.remote, opts.branch, push=opts.push), ensure_ascii=False, sort_keys=True))
    except (ImportHold, OSError, ValueError) as exc:
        print(json.dumps({"status": "HOLD", "reason": str(exc)}, ensure_ascii=False), file=sys.stderr)
        sys.exit(2)


if __name__ == "__main__":
    main()
