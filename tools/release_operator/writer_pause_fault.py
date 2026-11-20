#!/usr/bin/env python3
"""V1i: real SIGSTOP/SIGKILL writer-pause fault test on disposable CI processes.

Not a Moodle/production writer lock. Creates two synthetic file writers on a
GitHub-hosted runner; a separate controller stops both, fsyncs a hold receipt,
then dies with SIGKILL. Parent independently verifies that the STOP persists
without the controller and that neither writer advanced. Explicitly cleans up.
Always HOLD; never operates on SSH, Moodle, MySQL or production writers.
"""
from __future__ import annotations

import json
import os
from pathlib import Path
import signal
import subprocess
import sys
import tempfile
import time

SCHEMA = "bms24.release_operator.writer_pause_fault.v1i"
WORKERS = ("synthetic_web", "synthetic_cron")


class PauseHOLD(RuntimeError):
    pass


def ensure(condition: bool, reason: str):
    if not condition:
        raise PauseHOLD(reason)


def disposable_root() -> Path:
    e = os.environ
    ensure(e.get("GITHUB_ACTIONS") == "true" and e.get("CI") == "true" and
           e.get("BMS_WRITER_PAUSE_TEST") == "1",
           "isolated Actions opt-in required")
    value = e.get("RUNNER_TEMP", "")
    p = Path(value)
    ensure(value.startswith("/home/runner/work/_temp") and
           p.is_dir() and not p.is_symlink() and ".." not in p.parts,
           "must use disposable GitHub runner temp")
    ensure(sys.platform == "linux" and Path("/proc").is_dir(),
           "Linux /proc required to prove real process stop")
    return p


def _proc_state(pid: int) -> str:
    ensure(type(pid) is int and pid > 1, "invalid synthetic child PID")
    try:
        raw = (Path("/proc") / str(pid) / "status").read_text()
    except OSError as exc:
        raise PauseHOLD("synthetic worker process missing") from exc
    for line in raw.splitlines():
        if line.startswith("State:"):
            return line.split(":", 1)[1].strip().split()[0]
    raise PauseHOLD("worker state unavailable")


def _wait_stopped(pid: int):
    for _ in range(100):
        if _proc_state(pid) in ("T", "t"):
            return
        time.sleep(0.01)
    raise PauseHOLD("worker did not enter kernel stop state")


def _write_receipt(path: Path, content: dict):
    data = (json.dumps(content, sort_keys=True) + "\n").encode()
    fd = os.open(path, os.O_CREAT | os.O_EXCL | os.O_WRONLY | os.O_NOFOLLOW, 0o600)
    try:
        ensure(os.write(fd, data) == len(data), "incomplete durable receipt")
        os.fsync(fd)
    finally:
        os.close(fd)
    dirfd = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY)
    try:
        os.fsync(dirfd)
    finally:
        os.close(dirfd)


def _worker(which: str, counter: Path):
    disposable_root()
    ensure(which in WORKERS, "unknown synthetic writer")
    ensure(counter.parent.resolve() == Path(os.environ["BMS_PAUSE_SCRATCH"]).resolve(),
           "writer outside private CI scratch")
    # Synthetic writer appends local heartbeat integers. It has no DB/network.
    n = 0
    while True:
        with counter.open("a") as out:
            out.write(str(n) + "\n")
            out.flush()
        n += 1
        time.sleep(0.015)


def _controller(directory: Path):
    disposable_root()
    ensure(directory.resolve() == Path(os.environ["BMS_PAUSE_SCRATCH"]).resolve(),
           "untrusted scratch directory")
    children = []
    for name in WORKERS:
        counter = directory / (name + ".ticks")
        process = subprocess.Popen([sys.executable, str(Path(__file__).resolve()),
                                    "--worker", name, str(counter)],
                                   stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL,
                                   stderr=subprocess.DEVNULL, start_new_session=True)
        children.append((name, process.pid, counter))
    # Controller proves that each writer was advancing before suspension.
    for _ in range(100):
        if all(p.is_file() and len(p.read_text().splitlines()) >= 3
               for _, _, p in children):
            break
        time.sleep(0.02)
    else:
        raise PauseHOLD("synthetic workers not running")
    for _, pid, _ in children:
        os.kill(pid, signal.SIGSTOP)
        _wait_stopped(pid)
    _write_receipt(directory / "pause-receipt.json",
                   {"schema": SCHEMA, "simulation_only": True,
                    "phase": "WRITERS_STOPPED",
                    "workers": [{"role": n, "pid": pid, "bytes_at_stop": p.stat().st_size}
                                for n, pid, p in children]})
    os.kill(os.getpid(), signal.SIGKILL)
    raise PauseHOLD("controller was not terminated")


def run() -> dict:
    root = disposable_root()
    with tempfile.TemporaryDirectory(prefix="bmsci-writer-pause-", dir=root) as td:
        d = Path(td)
        d.chmod(0o700)
        env = dict(os.environ, BMS_PAUSE_SCRATCH=str(d))
        controller = subprocess.run([sys.executable, str(Path(__file__).resolve()),
                                     "--controller", str(d)],
                                    env=env, stdin=subprocess.DEVNULL,
                                    stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                    timeout=12)
        receiptfile = d / "pause-receipt.json"
        children = []
        try:
            if receiptfile.is_file():
                receipt = json.loads(receiptfile.read_text())
                children = receipt.get("workers", [])
            ensure(controller.returncode == -signal.SIGKILL,
                   "fault injection failed to kill controller")
            ensure(receiptfile.is_file() and
                   receipt.get("schema") == SCHEMA and
                   receipt.get("simulation_only") is True and
                   receipt.get("phase") == "WRITERS_STOPPED" and
                   len(children) == len(WORKERS), "durable pause receipt missing")
            sizes = {}
            for child in children:
                role = child["role"]
                ensure(role in WORKERS and type(child["pid"]) is int and
                       type(child["bytes_at_stop"]) is int,
                       "invalid worker identity")
                _wait_stopped(child["pid"])
                counter = d / (role + ".ticks")
                ensure(counter.is_file() and
                       counter.stat().st_size == child["bytes_at_stop"],
                       "writer changed after stop receipt")
                sizes[role] = counter.stat().st_size
            # This is a real elapsed hold, not a mocked stop, with controller dead.
            time.sleep(0.40)
            for child in children:
                ensure(_proc_state(child["pid"]) in ("T", "t"),
                       "writer resumed automatically after controller death")
                ensure((d / (child["role"] + ".ticks")).stat().st_size ==
                       sizes[child["role"]], "writer advanced during hold")
            return {
                "schema": SCHEMA,
                "status": "HOLD", "deployable": False,
                "controller_sigkill_confirmed": True,
                "kernel_stopped_worker_count": len(children),
                "writer_bytes_unchanged_during_hold": True,
                "manual_resume_required": True,
                "real_moodle_writer_pause_verified": False,
                "server_wide_lock_verified": False,
                "production_backup_restore_verified": False,
                "actions_executed": [
                    "synthetic_ci_file_writers_started",
                    "synthetic_process_sigstop",
                    "synthetic_controller_sigkill",
                    "synthetic_process_cleanup"],
            }
        finally:
            for child in children:
                pid = child.get("pid") if isinstance(child, dict) else None
                if type(pid) is not int or pid <= 1:
                    continue
                try:
                    os.kill(pid, signal.SIGCONT)
                    os.kill(pid, signal.SIGTERM)
                except ProcessLookupError:
                    pass


def main() -> int:
    try:
        if len(sys.argv) == 4 and sys.argv[1] == "--worker":
            _worker(sys.argv[2], Path(sys.argv[3]))
            return 2
        if len(sys.argv) == 3 and sys.argv[1] == "--controller":
            _controller(Path(sys.argv[2]))
            return 2
        ensure(len(sys.argv) == 1, "unsupported command")
        print(json.dumps(run(), sort_keys=True))
        return 0
    except (PauseHOLD, OSError, ValueError, KeyError) as exc:
        print(json.dumps({"schema": SCHEMA, "status": "REJECTED",
                          "deployable": False, "reason": str(exc)[:160]}))
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
