#!/usr/bin/env python3
"""V1c: durable *simulation* journal for a future BMS24 migration executor.

Only local JSONL receipt writes are implemented. This module NEVER invokes
SSH, PHP, a database, cron, backup, restore or a deployment. Every state is
HOLD; even SIMULATED_COMPLETE is not permission to touch production.

For the eventual real executor, DDL_INTENT must be durably committed before
the *first* schema-changing operation, and the writer pause must be maintained
by a separate trusted process. Neither integration exists in V1c.
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
import stat


class JournalHold(RuntimeError):
    pass


SCHEMA = "bms24.release_operator.phase_event.v1c"
RELEASE = re.compile(r"BMS24-[A-Z0-9][A-Z0-9-]{2,48}\Z")
PHASES = (
    "INIT",
    "OPERATOR_LOCK_ATTESTED",
    "WRITER_PAUSE_ATTESTED",
    "FRESH_BACKUP_ATTESTED",
    "ISOLATED_RESTORE_ATTESTED",
    "DDL_INTENT",
    "UPGRADE_ATTESTED",
    "SMOKE_ATTESTED",
    "SIMULATED_COMPLETE",
)
NEXT = dict(zip(PHASES, PHASES[1:]))
ZERO = "0" * 64
RECORD_KEYS = {
    "schema", "release_id", "target", "sequence", "phase", "previous_digest",
    "timestamp", "simulation_only", "digest"
}


def canonical(value) -> bytes:
    return json.dumps(value, sort_keys=True, ensure_ascii=True,
                      separators=(",", ":"), allow_nan=False).encode("utf-8")


def _validate_path(path: Path) -> Path:
    p = Path(path)
    if not p.is_absolute():
        raise JournalHold("absolute private journal path required")
    if not p.parent.is_dir() or p.parent.is_symlink():
        raise JournalHold("private journal parent unavailable or symlink")
    parent_mode = p.parent.stat().st_mode
    if not stat.S_ISDIR(parent_mode) or parent_mode & 0o077:
        raise JournalHold("private journal parent must have mode 0700")
    if p.is_symlink():
        raise JournalHold("journal symlink prohibited")
    return p


@contextmanager
def _lock(path: Path):
    """Advisory local serialization only; NOT an independent server release lock."""
    p = _validate_path(path)
    lock_path = p.with_name(p.name + ".lock")
    flags = os.O_RDWR | os.O_CREAT | os.O_CLOEXEC | os.O_NOFOLLOW
    fd = os.open(lock_path, flags, 0o600)
    try:
        mode = os.fstat(fd).st_mode
        if not stat.S_ISREG(mode) or mode & 0o077:
            raise JournalHold("unsafe local lock file")
        try:
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError as exc:
            raise JournalHold("local journal is already locked") from exc
        yield p
    finally:
        os.close(fd)


def _parse_lines(path: Path) -> list[dict]:
    if not path.exists():
        return []
    if path.is_symlink():
        raise JournalHold("journal symlink prohibited")
    fd = os.open(path, os.O_RDONLY | os.O_CLOEXEC | os.O_NOFOLLOW)
    try:
        info = os.fstat(fd)
        if not stat.S_ISREG(info.st_mode) or info.st_mode & 0o077:
            raise JournalHold("unsafe journal file")
        if info.st_size > 1024 * 1024:
            raise JournalHold("journal exceeded 1MiB")
        with os.fdopen(fd, "rb", closefd=False) as stream:
            raw = stream.read(1024 * 1024 + 1)
    finally:
        os.close(fd)
    if not raw or not raw.endswith(b"\n"):
        raise JournalHold("incomplete journal record; manual recovery HOLD")
    prev = ZERO
    result = []
    for line in raw.splitlines():
        if len(line) > 8192:
            raise JournalHold("journal record over limit")
        try:
            record = json.loads(line.decode("utf-8"))
        except (ValueError, UnicodeError) as exc:
            raise JournalHold("corrupt journal record; manual recovery HOLD") from exc
        if not isinstance(record, dict) or set(record) != RECORD_KEYS:
            raise JournalHold("unexpected journal fields")
        if record["schema"] != SCHEMA or record["simulation_only"] is not True:
            raise JournalHold("untrusted or executable journal record")
        if type(record["sequence"]) is not int or record["sequence"] != len(result):
            raise JournalHold("journal sequence mismatch")
        if record["previous_digest"] != prev or not isinstance(record["digest"], str):
            raise JournalHold("broken journal hash chain")
        content = {k: v for k, v in record.items() if k != "digest"}
        expected = hashlib.sha256(canonical(content)).hexdigest()
        if record["digest"] != expected:
            raise JournalHold("journal content hash mismatch")
        if not isinstance(record["timestamp"], str) or not record["timestamp"].endswith("Z"):
            raise JournalHold("invalid journal timestamp")
        if not isinstance(record["release_id"], str) or RELEASE.fullmatch(record["release_id"]) is None:
            raise JournalHold("invalid release identity in journal")
        if record["target"] != "synthetic":
            raise JournalHold("V1c journal cannot represent a production action")
        if not result:
            if record["phase"] != "INIT":
                raise JournalHold("journal lacks INIT")
        else:
            last = result[-1]
            if record["release_id"] != last["release_id"]:
                raise JournalHold("release identity changed in journal")
            if NEXT.get(last["phase"]) != record["phase"]:
                raise JournalHold("journal phase progression changed")
        prev = expected
        result.append(record)
        if len(result) > len(PHASES):
            raise JournalHold("journal has impossible number of records")
    return result


def load(path: Path, release_id: str) -> list[dict]:
    path = _validate_path(Path(path))
    records = _parse_lines(path)
    if records and records[0]["release_id"] != release_id:
        raise JournalHold("journal belongs to a different exact release")
    return records


def _append(path: Path, record: dict, new: bool) -> None:
    flags = os.O_WRONLY | os.O_CLOEXEC | os.O_NOFOLLOW
    if new:
        flags |= os.O_CREAT | os.O_EXCL
    else:
        flags |= os.O_APPEND
    fd = os.open(path, flags, 0o600)
    try:
        info = os.fstat(fd)
        if not stat.S_ISREG(info.st_mode) or info.st_mode & 0o077:
            raise JournalHold("unsafe journal file")
        data = canonical(record) + b"\n"
        if len(data) > 8192:
            raise JournalHold("record exceeds limit")
        written = 0
        while written < len(data):
            n = os.write(fd, data[written:])
            if n <= 0:
                raise JournalHold("journal write failure")
            written += n
        os.fsync(fd)
    finally:
        os.close(fd)
    parentfd = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC)
    try:
        os.fsync(parentfd)
    finally:
        os.close(parentfd)


def record(path: Path, release_id: str, next_phase: str) -> dict:
    """Record simulated phase after a local lock; there are no external actions."""
    if not isinstance(release_id, str) or RELEASE.fullmatch(release_id) is None:
        raise JournalHold("invalid release id")
    if next_phase not in PHASES:
        raise JournalHold("unsupported phase")
    with _lock(Path(path)) as safe:
        previous = _parse_lines(safe)
        if previous and previous[0]["release_id"] != release_id:
            raise JournalHold("release identity changed")
        if not previous:
            if next_phase != "INIT":
                raise JournalHold("INIT required first")
        elif NEXT.get(previous[-1]["phase"]) != next_phase:
            raise JournalHold("phase skipped or already recorded")
        bare = {
            "schema": SCHEMA,
            "release_id": release_id,
            "target": "synthetic",
            "sequence": len(previous),
            "phase": next_phase,
            "previous_digest": previous[-1]["digest"] if previous else ZERO,
            "timestamp": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
            "simulation_only": True,
        }
        new_record = {**bare, "digest": hashlib.sha256(canonical(bare)).hexdigest()}
        _append(safe, new_record, new=not previous)
        return status(safe, release_id)


def recovery_guidance(phase: str) -> dict:
    """Conservative advisory only; this NEVER resumes writers or restores data."""
    if phase not in PHASES:
        raise JournalHold("unknown recovery phase")
    idx = PHASES.index(phase)
    if phase == "SIMULATED_COMPLETE":
        policy = "SIMULATION_ONLY_NO_PRODUCTION_ASSURANCE"
    elif idx < PHASES.index("DDL_INTENT"):
        policy = "PRE_DDL_HOLD_VERIFY_NO_DDL_BEFORE_ANY_CODE_REVERT_OR_WRITER_RESUME"
    elif idx < PHASES.index("UPGRADE_ATTESTED"):
        policy = "POST_DDL_MAINTENANCE_HOLD_NO_CODE_ONLY_DOWNGRADE"
    else:
        policy = "VERIFY_1601_COMPATIBLE_CODE_FEATURE_OFF_NO_AUTOMATIC_DB_RESTORE"
    return {"status": "HOLD", "policy": policy,
            "automatic_writer_resume": False,
            "automatic_restore": False,
            "code_only_downgrade_allowed": False}


def status(path: Path, release_id: str) -> dict:
    records = load(path, release_id)
    if not records:
        raise JournalHold("journal has not been initialized")
    phase = records[-1]["phase"]
    return {
        "schema": "bms24.release_operator.phase_status.v1c",
        "release_id": release_id, "phase": phase,
        "sequence": records[-1]["sequence"],
        "journal_head_sha256": records[-1]["digest"],
        "simulated": True, "deployable": False,
        "actions_executed": [],
        "recovery": recovery_guidance(phase),
    }
