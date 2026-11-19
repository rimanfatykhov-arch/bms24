#!/usr/bin/env python3
"""V1d: durable inter-process release *reservation* (synthetic-only).

This file never calls a site, database, backup, cron, PHP, SSH or deployment.
A 0700 private directory and a durable reservation block a second model
operator even after SIGKILL. fcntl/flock serializes state updates only.
There is intentionally NO release/unlock/delete API. A future trusted
operator must design separate independently reviewed manual recovery.

Not a continuous writer-pause lease and NOT a production executor.
"""
from __future__ import annotations

from contextlib import contextmanager
from datetime import datetime, timezone
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import secrets
import stat

SCHEMA = "bms24.release_operator.reservation.v1d"
HEX32 = re.compile(r"[a-f0-9]{32}\Z")
RELEASE = re.compile(r"BMS24-[A-Z0-9][A-Z0-9-]{2,48}\Z")
ORDER = (
    "RESERVED",
    "WRITER_PAUSE_ASSERTED",
    "BACKUP_RESTORE_ASSERTED",
    "DDL_INTENT",
    "UPGRADE_ASSERTED",
    "SMOKE_ASSERTED",
    "MODEL_COMPLETE",
)
NEXT = dict(zip(ORDER, ORDER[1:]))
FIELDS = {"schema", "release_id", "target", "lease_id", "phase",
          "sequence", "created_at", "simulation_only", "digest"}


class GuardHold(RuntimeError):
    pass


def must(ok: bool, message: str) -> None:
    if not ok:
        raise GuardHold(message)


def _raw(data: dict) -> bytes:
    return json.dumps(data, sort_keys=True, separators=(",", ":"),
                      ensure_ascii=True, allow_nan=False).encode("ascii")


def _root(path: Path) -> Path:
    path = Path(path)
    must(path.is_absolute() and path.is_dir() and not path.is_symlink(),
         "private reservation directory must exist and be absolute")
    mode = path.stat().st_mode
    must(stat.S_ISDIR(mode) and not (mode & 0o077), "private directory must have 0700 permissions")
    return path


@contextmanager
def _lock(root: Path):
    root = _root(root)
    lock_path = root / "operator.lock"
    fd = os.open(lock_path, os.O_CLOEXEC | os.O_NOFOLLOW | os.O_CREAT | os.O_RDWR, 0o600)
    try:
        st = os.fstat(fd)
        must(stat.S_ISREG(st.st_mode) and not (st.st_mode & 0o077), "unsafe operator lock")
        try:
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError as exc:
            raise GuardHold("another local model operator is active") from exc
        yield root
    finally:
        os.close(fd)


def _read(root: Path):
    state_path = root / "reservation.json"
    must(not state_path.is_symlink(), "reservation symlink forbidden")
    if not state_path.exists():
        return None
    fd = os.open(state_path, os.O_RDONLY | os.O_CLOEXEC | os.O_NOFOLLOW)
    try:
        st = os.fstat(fd)
        must(stat.S_ISREG(st.st_mode) and not (st.st_mode & 0o077) and st.st_size < 8192,
             "unsafe reservation file")
        data = os.read(fd, 8192)
    finally:
        os.close(fd)
    must(data.endswith(b"\n"), "incomplete durable reservation")
    try:
        record = json.loads(data)
    except (ValueError, UnicodeError) as exc:
        raise GuardHold("corrupt durable reservation") from exc
    must(isinstance(record, dict) and set(record) == FIELDS, "unknown reservation fields")
    unsigned = {k: v for k, v in record.items() if k != "digest"}
    must(record["digest"] == hashlib.sha256(_raw(unsigned)).hexdigest(),
         "reservation checksum invalid")
    must(record["schema"] == SCHEMA and record["target"] == "synthetic" and
         record["simulation_only"] is True and
         record["phase"] in ORDER and
         type(record["sequence"]) is int and
         record["sequence"] == ORDER.index(record["phase"]) and
         isinstance(record["lease_id"], str) and
         HEX32.fullmatch(record["lease_id"]) is not None and
         isinstance(record["release_id"], str) and
         RELEASE.fullmatch(record["release_id"]) is not None,
         "invalid reservation data")
    return record


def _write(root: Path, unsigned: dict) -> None:
    final = root / "reservation.json"
    must(not final.is_symlink(), "reservation path is a symlink")
    record = {**unsigned, "digest": hashlib.sha256(_raw(unsigned)).hexdigest()}
    tmp = root / (".pending-" + secrets.token_hex(12))
    fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_EXCL |
                 os.O_NOFOLLOW | os.O_CLOEXEC, 0o600)
    try:
        payload = _raw(record) + b"\n"
        n = 0
        while n < len(payload):
            count = os.write(fd, payload[n:])
            must(count > 0, "reservation write failed")
            n += count
        os.fsync(fd)
    finally:
        os.close(fd)
    try:
        os.replace(tmp, final)
        directory = os.open(root, os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC)
        try:
            os.fsync(directory)
        finally:
            os.close(directory)
    finally:
        if tmp.exists():
            tmp.unlink()


def _validate_release(release_id: str) -> None:
    must(isinstance(release_id, str) and RELEASE.fullmatch(release_id) is not None,
         "invalid release id")


def acquire(root: Path, release_id: str) -> dict:
    """Persist a new synthetic reservation before any simulated operation."""
    _validate_release(release_id)
    with _lock(root) as root:
        must(_read(root) is None,
             "durable reservation already exists: manual recovery HOLD; no automatic takeover")
        lease_id = secrets.token_hex(16)
        unsigned = {
            "schema": SCHEMA, "release_id": release_id, "target": "synthetic",
            "lease_id": lease_id, "phase": "RESERVED", "sequence": 0,
            "created_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
            "simulation_only": True
        }
        _write(root, unsigned)
    return {"status": "HOLD", "lease_id": lease_id, "phase": "RESERVED",
            "deployable": False, "actions_executed": [], "simulation_only": True}


def advance(root: Path, release_id: str, lease_id: str, new_phase: str) -> dict:
    """Advance a symbolic assertion; never call a real writer or upgrade."""
    _validate_release(release_id)
    must(isinstance(lease_id, str) and HEX32.fullmatch(lease_id) is not None,
         "invalid lease handle")
    with _lock(root) as root:
        old = _read(root)
        must(old is not None, "reservation does not exist")
        must(old["release_id"] == release_id and old["lease_id"] == lease_id,
             "wrong reservation owner/release")
        must(NEXT.get(old["phase"]) == new_phase, "unsafe/duplicate phase transition")
        unsigned = {k: v for k, v in old.items() if k != "digest"}
        unsigned["phase"] = new_phase
        unsigned["sequence"] += 1
        _write(root, unsigned)
    return inspect(root)


def inspect(root: Path) -> dict:
    """Read state; a stale reservation stays HOLD after owner process death."""
    root = _root(root)
    state = _read(root)
    if state is None:
        return {"status": "HOLD", "deployable": False, "reservation_exists": False,
                "actions_executed": []}
    post_ddl = ORDER.index(state["phase"]) >= ORDER.index("DDL_INTENT")
    return {
        "status": "HOLD", "deployable": False,
        "reservation_exists": True, "release_id": state["release_id"],
        "phase": state["phase"], "simulation_only": True,
        "actions_executed": [],
        "manual_recovery_required": True,
        "writer_pause_actually_held": False,
        "automatic_lock_takeover": False,
        "automatic_db_restore": False,
        "automatic_writer_resume": False,
        "code_only_downgrade_allowed": False,
        "recovery_policy": ("POST_DDL_MAINTENANCE_HOLD_NO_CODE_ONLY_DOWNGRADE" if post_ddl
                            else "PRE_DDL_HOLD_VERIFY_NO_DDL_BEFORE_ANY_RECOVERY"),
    }
