#!/usr/bin/env python3
"""Run one approved synthetic Scheduler task with the isolated Codex CLI."""
import argparse
from contextlib import ExitStack, closing, contextmanager
from datetime import datetime, timedelta, timezone
import hashlib
import json
import os
from pathlib import Path
from queue import Empty, Full, Queue
import re
import shutil
import sqlite3
import subprocess
import tempfile
from threading import Thread
import time

from policy import fresh, sha, timestamp
from scheduler import WEB, build_plan


ADAPTER = "codex-cli"
CARD = {"task": "bdo3-synthetic-sum-v1", "values": [7, 11, 19]}
RESULT_SCHEMA = {"type": "object", "additionalProperties": False,
                 "properties": {"task": {"type": "string"}, "total": {"type": "integer"}},
                 "required": ["task", "total"]}
LIMIT = 1024 * 1024
SESSION = re.compile(r"[0-9a-fA-F]{8}(?:-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}")
ACTIVE = ("VALIDATED", "STARTING", "RUNNING", "RECOVERY_REQUIRED")


class Blocked(ValueError):
    pass


def require(condition, reason):
    if not condition:
        raise Blocked(reason)


def now():
    return datetime.now(timezone.utc)


def digest(raw):
    return hashlib.sha256(raw).hexdigest()


def encoded(data):
    return (json.dumps(data, sort_keys=True, ensure_ascii=False) + "\n").encode()


def read(path):
    require(path.is_file() and not path.is_symlink(), "input_unavailable")
    with path.open("rb") as stream:
        raw = stream.read(LIMIT + 1)
    require(len(raw) <= LIMIT, "input_too_large")
    data = json.loads(raw)
    require(isinstance(data, dict), "invalid_input")
    return data, digest(raw)


def put(path, data):
    # Publish a complete JSON file without replacing any existing evidence.
    fd, temporary = tempfile.mkstemp(dir=path.parent)
    try:
        with os.fdopen(fd, "wb") as stream:
            stream.write(encoded(data))
            stream.flush()
            os.fsync(stream.fileno())
        os.link(temporary, path)
    finally:
        os.unlink(temporary)


def clean_checkout(workspace, base):
    env = {key: value for key, value in os.environ.items() if not key.upper().startswith("GIT_")}
    env.update(GIT_CONFIG_NOSYSTEM="1", GIT_CONFIG_GLOBAL=os.devnull, GIT_OPTIONAL_LOCKS="0")

    def git(*args):
        return subprocess.check_output(["git", "-c", "core.fsmonitor=false", "-C", str(workspace), *args], timeout=30,
                                       env=env, stderr=subprocess.DEVNULL).decode().strip()

    require(Path(git("rev-parse", "--show-toplevel")).resolve() == workspace
            and git("rev-parse", "HEAD") == base, "workspace_sha_changed")
    require(not git("remote") and not git("status", "--porcelain=v1", "--untracked-files=all", "--ignored"),
            "workspace_not_isolated_or_clean")


def authorize(args, current):
    go, go_hash = read(args.approval)
    require(go_hash == args.approval_sha256 and type(go.get("schema_version")) is int
            and go["schema_version"] == 1 and go.get("decision") == "GO"
            and go.get("repository") == WEB and go.get("actor") == "Codex"
            and go.get("adapter") == ADAPTER and go.get("card") == CARD["task"], "approval_mismatch")
    require(isinstance(go.get("approval_id"), str) and re.fullmatch(r"[A-Za-z0-9_-]{1,100}", go["approval_id"]),
            "invalid_approval_id")
    start, end = timestamp(go.get("not_before")), timestamp(go.get("expires_at"))
    require(start and end and start < end and (args.reconcile or start <= current < end), "approval_outside_window")
    plan, plan_hash = read(args.plan)
    snapshot, snapshot_hash = read(args.snapshot)
    queue, queue_hash = read(args.queue)
    require(all(type(doc.get("schema_version")) is int and doc["schema_version"] == 1
                for doc in (plan, snapshot, queue)), "unsupported_schema")
    require((plan_hash, snapshot_hash, queue_hash) ==
            (go.get("plan_sha256"), go.get("snapshot_sha256"), go.get("queue_sha256")), "input_identity_changed")
    planned_at = timestamp(plan.get("as_of"))
    require(planned_at and (args.reconcile or fresh(plan["as_of"], current, timedelta(minutes=10))), "stale_plan")
    require(build_plan(snapshot, queue, planned_at) == plan, "scheduler_plan_mismatch")
    checked = plan if args.reconcile else build_plan(snapshot, queue, current)
    require(not checked["diagnostics"], checked["diagnostics"][0] if checked["diagnostics"] else "invalid_snapshot")
    recommendations = [row for row in checked["recommendations"] if row["number"] == go.get("number")]
    require(len(recommendations) == 1, "task_blocked_or_unassigned")
    task = recommendations[0]
    require(task["actor"] == task["executor"] == "Codex" and task["action"] == "continue_work"
            and task["kind"] == "issue" and sha(go.get("base_sha")) == task["head"] == checked["main"],
            "task_identity_mismatch")
    require(type(go.get("number")) is int and go.get("result") == "result.json", "pilot_scope_mismatch")
    return go, go_hash, plan_hash


def layout(args, go):
    workspace = Path(go["workspace"])
    home = Path(go["codex_home"])
    cli = Path(go["codex_cli"])
    for p in (workspace, home, cli):
        require(p.is_absolute() and p.resolve(strict=True) == p, "invalid_isolation_path")
    root = args.approval.resolve(strict=True).parent
    require(workspace.parent == home.parent == root and workspace != home and cli.is_file()
            and cli.name.lower() in ("codex", "codex.exe") and workspace.is_dir() and home.is_dir(),
            "invalid_isolation_layout")
    require(digest(cli.read_bytes()) == go.get("codex_cli_sha256"), "adapter_binary_changed")
    require(all(p.name == "auth.json" and p.is_file() and not p.is_symlink() for p in home.iterdir()),
            "pilot_profile_has_extra_files")
    clean_checkout(workspace, go["base_sha"])
    return root, workspace, home, cli


def session_clear(path, workspace, current, proof_hash):
    proof, actual = read(path)
    require(actual == proof_hash and proof.get("workspace") == str(workspace)
            and fresh(proof.get("observed_at"), current, timedelta(seconds=60)), "session_state_unknown")
    require(proof.get("state") == "vacant" and proof.get("session_id") is None, "session_busy_or_unknown")


def record(db, key, status, reason, session=None):
    db.execute("UPDATE pilot SET state=?,reason=?,session=? WHERE key=?", (status, reason, session, key))
    source = ADAPTER if status == "RUNNING" else "runner"
    db.execute("INSERT INTO events VALUES(?,?,?,?,?,?)", (key, now().isoformat(), source, status, reason, session))


@contextmanager
def workspace_lock(path):
    require(not path.is_symlink(), "workspace_lock_symlink")
    with path.open("a+b") as stream:
        if os.name == "nt":
            import msvcrt
            if stream.tell() == 0:
                stream.write(b"0")
                stream.flush()
            stream.seek(0)
            acquire = lambda: msvcrt.locking(stream.fileno(), msvcrt.LK_NBLCK, 1)
        else:
            import fcntl
            acquire = lambda: fcntl.flock(stream, fcntl.LOCK_EX | fcntl.LOCK_NB)
        try:
            acquire()
        except OSError as error:
            raise Blocked("workspace_busy") from error
        yield


def process_output(stream, events):
    try:
        while raw := stream.readline(LIMIT + 1):
            events.put(raw, timeout=1)
            if len(raw) > LIMIT:
                break
    except (Full, OSError):
        pass
    finally:
        try:
            events.put(None, timeout=1)
        except Full:
            pass


def failure_code(message):
    lower = str(message).lower()
    if any(word in lower for word in ("rate limit", "quota", "429")):
        return "model_limit"
    if any(word in lower for word in ("permission", "unauthorized", "authentication", "sandbox")):
        return "adapter_access_denied"
    return "adapter_failed"


def codex(cli, home, scratch, started, cutoff):
    schema = scratch / "response.schema.json"
    put(schema, RESULT_SCHEMA)
    keep = ("PATH", "SYSTEMROOT", "WINDIR", "COMSPEC", "PATHEXT")
    env = {k: v for k, v in os.environ.items() if k.upper() in keep}
    profile = scratch / "profile"
    profile.mkdir(mode=0o700)
    if (home / "auth.json").is_file():
        shutil.copyfile(home / "auth.json", profile / "auth.json")
        (profile / "auth.json").chmod(0o600)
    env.update(CODEX_HOME=str(profile), TMP=str(scratch), TEMP=str(scratch), TMPDIR=str(scratch))
    argv = [str(cli), "exec", "--ignore-user-config", "--strict-config", "--ephemeral", "--skip-git-repo-check", "--json",
            "--sandbox", "read-only", "--disable", "shell_tool", "--disable", "hooks",
            "--disable", "enable_mcp_apps", "--disable", "skill_search", "--disable", "multi_agent",
            "--disable", "multi_agent_v2",
            "-c", 'approval_policy="never"', "-c", 'web_search="disabled"',
            "--output-schema", str(schema),
            "Return JSON with the task and the sum of values in this synthetic card: " + json.dumps(CARD)]
    if time.monotonic() >= cutoff:
        return "BLOCKED", "approval_outside_window", None, None
    try:
        proc = subprocess.Popen(argv, cwd=scratch, env=env, stdin=subprocess.DEVNULL,
                                stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, shell=False)
    except OSError:
        return "FAILED", "adapter_unavailable", None, None
    events = Queue(maxsize=16)
    reader = Thread(target=process_output, args=(proc.stdout, events), daemon=True)
    reader.start()
    session, result, total, failure = None, None, 0, "invalid_result"
    idle = time.monotonic() + 90
    try:
        while True:
            remaining = min(cutoff, idle) - time.monotonic()
            if remaining <= 0:
                raise Empty
            raw = events.get(timeout=remaining)
            if raw is None:
                break
            total += len(raw)
            if total > LIMIT:
                failure = "output_limit"
                break
            event = json.loads(raw)
            kind = event.get("type")
            idle = time.monotonic() + 90
            if kind in ("item.started", "item.updated", "item.completed") and event.get("item", {}).get("type") not in (
                    "reasoning", "agent_message"):
                failure = "unexpected_adapter_action"
                break
            if kind == "thread.started":
                candidate = event.get("thread_id")
                if session or not isinstance(candidate, str) or not SESSION.fullmatch(candidate):
                    failure = "unexpected_session"
                    break
                session = candidate
                started(session)
            elif kind in ("turn.failed", "error"):
                failure = failure_code(event.get("error", event.get("message")))
                break
            elif kind == "item.completed" and event.get("item", {}).get("type") == "agent_message":
                result = event["item"].get("text")
            elif kind == "turn.completed" and session and isinstance(result, str):
                remaining = cutoff - time.monotonic()
                if remaining <= 0:
                    raise Empty
                if proc.wait(timeout=min(5, remaining)) == 0:
                    if time.monotonic() >= cutoff:
                        raise Empty
                    return "REVIEW_READY", "agent_completed", session, json.loads(result)
                failure = "adapter_failed"
                break
    except (Empty, subprocess.TimeoutExpired):
        failure = "time_limit" if time.monotonic() >= cutoff else "no_progress"
    except (ValueError, AttributeError, RecursionError):
        failure = "invalid_adapter_output"
    finally:
        if proc.poll() is None:
            try:
                proc.kill()
                proc.wait(timeout=5)
            except (OSError, subprocess.TimeoutExpired):
                pass
        reader.join(timeout=0.2)
        if not reader.is_alive():
            proc.stdout.close()
    if proc.poll() is None:
        return "RECOVERY_REQUIRED", "stop_unconfirmed", session, None
    status = "TIMED_OUT" if failure in ("time_limit", "no_progress") else "FAILED"
    return status, failure, session, None


def finish(db, key, output, workspace, go, plan_hash, outcome):
    status, reason, session, result = outcome
    evidence = {"task": go["number"], "base_sha": go["base_sha"], "actor": "Codex", "reviewer": "Claude",
                "approval_id": go["approval_id"], "plan_sha256": plan_hash, "session_id": session,
                "snapshot_sha256": go["snapshot_sha256"], "queue_sha256": go["queue_sha256"],
                "status": status, "reason": reason, "at": now().isoformat()}
    if status == "REVIEW_READY":
        clean_checkout(workspace, go["base_sha"])
        expected = {"task": CARD["task"], "total": sum(CARD["values"])}
        require(type(result) is dict and type(result.get("total")) is int and result == expected,
                "invalid_result")
        path = output / "result.json"
        if path.exists():
            require(read(path)[0] == result, "result_changed")
        else:
            put(path, result)
        evidence.update(result="result.json", result_sha256=digest(encoded(result)))
    if (output / "receipt.json").exists():
        previous, _ = read(output / "receipt.json")
        require({k: v for k, v in previous.items() if k != "at"} ==
                {k: v for k, v in evidence.items() if k != "at"}, "receipt_changed")
        evidence = previous
    else:
        put(output / "receipt.json", evidence)
    with db:
        record(db, key, status, reason, session)
    return evidence


def start(args, go, go_hash, plan_hash, paths, adapter=codex):
    root, workspace, home, cli = paths
    journal = root / "pilot.sqlite3"
    require(not journal.is_symlink(), "journal_symlink")
    key = digest(encoded([WEB, go["number"], "Codex", go["base_sha"], go["approval_id"]]))
    with ExitStack() as stack:
        db = stack.enter_context(closing(sqlite3.connect(journal, timeout=5)))
        db.executescript("""CREATE TABLE IF NOT EXISTS pilot (
            key TEXT PRIMARY KEY, approval TEXT NOT NULL, state TEXT NOT NULL, reason TEXT NOT NULL, session TEXT);
            CREATE TABLE IF NOT EXISTS events (key TEXT, at TEXT, source TEXT, state TEXT, reason TEXT, session TEXT);
        """)
        output = root / ("run-" + key)
        db.execute("BEGIN IMMEDIATE")
        prior = db.execute("SELECT approval,state,reason,session FROM pilot WHERE key=?", (key,)).fetchone()
        if prior:
            require(prior[0] == go_hash, "approval_reused_with_different_inputs")
            if args.reconcile and prior[1] in ACTIVE:
                stack.enter_context(workspace_lock(root / "workspace.lock"))
                if (output / "outcome.json").is_file():
                    saved, _ = read(output / "outcome.json")
                    require(saved.get("key") == key and saved.get("approval_sha256") == go_hash, "outcome_identity_changed")
                    return finish(db, key, output, workspace, go, plan_hash, saved["outcome"])
                # Neither CLI resume nor guessing a PID can establish that the previous start never happened.
                record(db, key, "RECOVERY_REQUIRED", "start_outcome_unknown", prior[3])
                db.commit()
                return {"status": "RECOVERY_REQUIRED", "reason": "start_outcome_unknown", "session_id": prior[3]}
            if prior[1] not in ACTIVE and (output / "receipt.json").is_file():
                return read(output / "receipt.json")[0]
            return {"status": prior[1], "reason": prior[2], "session_id": prior[3]}
        require(not args.reconcile, "no_previous_launch")
        require(not db.execute("SELECT 1 FROM pilot WHERE state IN (?,?,?,?)", ACTIVE).fetchone(), "pilot_busy")
        stack.enter_context(workspace_lock(root / "workspace.lock"))
        session_clear(args.session, workspace, now(), go.get("session_sha256"))
        output.mkdir(mode=0o700)
        db.execute("INSERT INTO pilot VALUES(?,?,?,?,?)", (key, go_hash, "VALIDATED", "verified", None))
        record(db, key, "VALIDATED", "verified")
        record(db, key, "STARTING", "launch_reserved")
        db.commit()

        def started(session):
            with db:
                record(db, key, "RUNNING", "session_observed", session)

        try:
            require(layout(args, go) == paths, "isolation_changed")
            current = now()
            require(authorize(args, current)[1] == go_hash, "approval_changed")
            current = now()
            session_clear(args.session, workspace, current, go.get("session_sha256"))
            seconds = min(600, (timestamp(go["expires_at"]) - current).total_seconds())
            require(seconds > 0, "approval_outside_window")
            cutoff = time.monotonic() + seconds
        except (KeyError, ValueError, OSError, RecursionError, subprocess.SubprocessError):
            return finish(db, key, output, workspace, go, plan_hash, ("BLOCKED", "prelaunch_recheck_failed", None, None))
        with tempfile.TemporaryDirectory(prefix="adapter-", dir=output) as temporary:
            outcome = adapter(cli, home, Path(temporary), started, cutoff)
        try:
            clean_checkout(workspace, go["base_sha"])
        except (Blocked, OSError, subprocess.SubprocessError):
            outcome = ("RECOVERY_REQUIRED", "workspace_integrity_changed", outcome[2], None)
        if outcome[0] == "REVIEW_READY":
            result = outcome[3]
            if (type(result) is not dict or type(result.get("total")) is not int
                    or result != {"task": CARD["task"], "total": sum(CARD["values"])}):
                outcome = ("FAILED", "invalid_result", outcome[2], None)
        put(output / "outcome.json", {"key": key, "approval_sha256": go_hash, "outcome": outcome})
        return finish(db, key, output, workspace, go, plan_hash, outcome)


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    for name in ("approval", "plan", "snapshot", "queue", "session"):
        parser.add_argument("--" + name, type=Path, required=True)
    parser.add_argument("--approval-sha256", required=True)
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument("--execute", action="store_true")
    mode.add_argument("--reconcile", action="store_true")
    args = parser.parse_args(argv)
    try:
        go, go_hash, plan_hash = authorize(args, now())
        paths = layout(args, go)
        if args.execute or args.reconcile:
            result = start(args, go, go_hash, plan_hash, paths)
        else:
            session_clear(args.session, paths[1], now(), go.get("session_sha256"))
            result = {"status": "VALIDATED", "mode": "dry-run", "task": go["number"], "plan_sha256": plan_hash}
    except Blocked as error:
        result = {"status": "BLOCKED", "reason": str(error)}
    except (KeyError, OSError, ValueError, RecursionError, sqlite3.Error, subprocess.SubprocessError):
        result = {"status": "BLOCKED", "reason": "invalid_input_or_local_state"}
    print(encoded(result).decode().strip())
    return 0 if result["status"] in ("VALIDATED", "REVIEW_READY") else 1


if __name__ == "__main__":
    raise SystemExit(main())
