#!/usr/bin/env python3
"""V1g — pinned, read-only SSH transport for the V1f forced-command.

The only remote verbs are 'inventory' and 'capacity'. All identity material is
loaded from a protected, host-local operator policy. No SSH invocation occurs
unless this file is executed by an authorized operator. Running the unit tests
uses a fake executor and never contacts the server.

An authenticated SSH host key proves the endpoint key, NOT a production release
approval or the correctness of reported server measurements. ALWAYS HOLD.
"""
from __future__ import annotations

import argparse
from datetime import datetime, timedelta, timezone
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import subprocess
from typing import Callable

SCHEMA = "bms24.release_operator.ssh_reader_policy.v1g"
REPORT = "bms24.release_operator.ssh_read_report.v1g"
POLICY = Path("/etc/bms24-release-operator/readonly-client.json")
SSH_BIN = "/usr/bin/ssh"
ALLOWED = ("inventory", "capacity")
SHA256 = re.compile(r"[a-f0-9]{64}\Z")
HOST = re.compile(r"[A-Za-z0-9](?:[A-Za-z0-9.-]{0,251}[A-Za-z0-9])?\Z")
USER = re.compile(r"[a-z_][a-z0-9_-]{0,31}\Z")
MAX_OUTPUT = 1024 * 1024
MAX_POLICY = 16 * 1024


class RemoteHold(ValueError):
    pass


def require(ok: bool, message: str) -> None:
    if not ok:
        raise RemoteHold(message)


def _unique(pairs):
    out = {}
    for key, value in pairs:
        if key in out:
            raise RemoteHold("duplicate JSON field")
        out[key] = value
    return out


def _decode(data: bytes):
    try:
        return json.loads(data.decode("utf-8"), object_pairs_hook=_unique)
    except (ValueError, UnicodeError) as exc:
        raise RemoteHold("invalid JSON evidence or policy") from exc


def protected_file(path: Path, maximum: int) -> bytes:
    """Strict local policy: trusted owner, no symlinks, no group/other writes."""
    require(path.is_absolute(), "protected path must be absolute")
    root = Path("/")
    for part in path.parts[1:]:
        root = root / part
        try:
            meta = root.lstat()
        except OSError as exc:
            raise RemoteHold("protected path unavailable") from exc
        require(not stat.S_ISLNK(meta.st_mode) and not (meta.st_mode & 0o022),
                "protected path is symlink or writable by others")
    st = path.stat()
    require(stat.S_ISREG(st.st_mode) and st.st_uid in (0, os.geteuid()) and
            0 < st.st_size <= maximum, "untrusted protected file")
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC)
    try:
        now = os.fstat(fd)
        require(now.st_dev == st.st_dev and now.st_ino == st.st_ino and
                now.st_uid in (0, os.geteuid()) and
                not (now.st_mode & 0o022) and now.st_size <= maximum,
                "protected file changed")
        data = os.read(fd, maximum + 1)
        require(0 < len(data) <= maximum, "protected file size invalid")
        return data
    finally:
        os.close(fd)


def validate_policy(data: bytes) -> dict:
    p = _decode(data)
    require(isinstance(p, dict) and set(p) == {
        "schema", "target", "host", "port", "user", "identity_file",
        "known_hosts_file", "known_hosts_sha256",
    }, "unexpected SSH policy fields")
    require(p["schema"] == SCHEMA and p["target"] == "edu.kzn.mba",
            "wrong release operator target")
    require(isinstance(p["host"], str) and HOST.fullmatch(p["host"]) is not None and
            ".." not in p["host"], "invalid pinned SSH hostname")
    require(type(p["port"]) is int and 1 <= p["port"] <= 65535, "invalid SSH port")
    require(isinstance(p["user"], str) and USER.fullmatch(p["user"]) is not None and
            p["user"] != "root", "restricted SSH account only")
    require(isinstance(p["known_hosts_sha256"], str) and
            SHA256.fullmatch(p["known_hosts_sha256"]) is not None,
            "missing independently pinned known_hosts digest")
    for key in ("identity_file", "known_hosts_file"):
        require(isinstance(p[key], str) and p[key].startswith("/") and "\x00" not in p[key] and
                ".." not in Path(p[key]).parts, "unsafe " + key)
    require(p["identity_file"] != p["known_hosts_file"], "SSH identity and host pin must differ")
    return p


def build_command(policy: dict, verb: str) -> list[str]:
    require(verb in ALLOWED, "only read-only inventory and capacity permitted")
    require(isinstance(policy, dict), "missing policy")
    return [
        SSH_BIN, "-T", "-F", "/dev/null",
        "-o", "BatchMode=yes",
        "-o", "NumberOfPasswordPrompts=0",
        "-o", "StrictHostKeyChecking=yes",
        "-o", "UpdateHostKeys=no",
        "-o", "VerifyHostKeyDNS=no",
        "-o", "GlobalKnownHostsFile=/dev/null",
        "-o", "UserKnownHostsFile=" + policy["known_hosts_file"],
        "-o", "IdentitiesOnly=yes",
        "-o", "IdentityAgent=none",
        "-o", "ForwardAgent=no",
        "-o", "ClearAllForwardings=yes",
        "-o", "RequestTTY=no",
        "-o", "ConnectTimeout=10",
        "-o", "ConnectionAttempts=1",
        "-i", policy["identity_file"],
        "-p", str(policy["port"]),
        "--", policy["user"] + "@" + policy["host"], verb,
    ]


def valid_age(ts: object, now: datetime) -> bool:
    if not isinstance(ts, str):
        return False
    try:
        sample = datetime.fromisoformat(ts.replace("Z", "+00:00"))
    except ValueError:
        return False
    if sample.tzinfo is None or now.tzinfo is None:
        return False
    delta = now.astimezone(timezone.utc) - sample.astimezone(timezone.utc)
    return timedelta(seconds=-30) <= delta <= timedelta(minutes=5)


def evaluate(verb: str, raw: bytes, *, now: datetime) -> dict:
    require(0 < len(raw) <= MAX_OUTPUT, "empty or oversized SSH response")
    body = _decode(raw)
    require(isinstance(body, dict), "remote response must be an object")
    result = {
        "schema": REPORT, "status": "HOLD", "deployable": False,
        "target": "edu.kzn.mba", "remote_verb": verb,
        "ssh_transport_authenticated_by_pinned_key": True,
        "server_runtime_identity_independently_verified": False,
        "provider_quota_verified": False,
        "verified_live_backup_restore": False,
        "continuous_writer_pause_verified": False,
        "actions_executed": [],
    }
    if verb == "inventory":
        require(body.get("read_only") is True and
                body.get("moodle_bootstrap") is False and
                body.get("php_sapi") == "cli" and
                valid_age(body.get("captured_utc"), now),
                "remote inventory is not fresh read-only CLI evidence")
        versions = body.get("installed_plugin_versions")
        require(isinstance(versions, dict) and
                isinstance(versions.get("local_kbacompany"), str) and
                isinstance(versions.get("local_kbacorporate"), str),
                "remote inventory missing BMS/Corporate versions")
        require(isinstance(body.get("code"), dict) and
                isinstance(body.get("server"), dict),
                "remote inventory missing server/code metadata")
        result["observed_plugin_versions"] = {
            "local_kbacompany": versions["local_kbacompany"],
            "local_kbacorporate": versions["local_kbacorporate"],
        }
        result["captured_at"] = body["captured_utc"]
        result["reported_metadata_sha256"] = hashlib.sha256(raw).hexdigest()
    elif verb == "capacity":
        require(body.get("schema") == "bms24.release_operator.capacity_attestation.v1f" and
                body.get("status") == "HOLD" and body.get("deployable") is False and
                body.get("target") == "edu.kzn.mba" and
                body.get("actions_executed") == [] and
                body.get("server_identity_authenticated") is False and
                body.get("provider_quota_verified") is False,
                "remote capacity claimed authority or wrong identity")
        measure = body.get("measurement")
        require(isinstance(measure, dict) and
                measure.get("schema") == "bms24.release_operator.storage_report.v1e" and
                measure.get("status") == "HOLD" and
                measure.get("deployable") is False and
                measure.get("actions_executed") == [] and
                valid_age(measure.get("captured_at"), now),
                "remote capacity measurement missing, stale or authoritative")
        require(isinstance(measure.get("declared_reserve_met"), bool) and
                isinstance(measure.get("filesystems"), list) and
                len(measure["filesystems"]) > 0,
                "capacity result incomplete")
        result["declared_reserve_met"] = measure["declared_reserve_met"]
        result["captured_at"] = measure["captured_at"]
        result["reported_metadata_sha256"] = hashlib.sha256(raw).hexdigest()
    else:
        raise RemoteHold("unknown remote verb")
    result["blockers"] = [
        "SSH host key pin authenticates a transport endpoint, not the production runtime or safety of measurements",
        "Provider account quota, fresh consistent backup/restore and continuous writer pause not verified",
        "No DDL-safe migration executor or approved release window; status remains HOLD",
    ]
    return result


def collect(policy: dict, verb: str, *, runner: Callable = subprocess.run,
            now: datetime | None = None) -> dict:
    require(verb in ALLOWED, "unknown remote command")
    # Host identity is pinned by the operator's protected policy and SSH host key.
    known_hosts = protected_file(Path(policy["known_hosts_file"]), MAX_POLICY)
    require(hashlib.sha256(known_hosts).hexdigest() == policy["known_hosts_sha256"],
            "SSH host pin changed")
    private = Path(policy["identity_file"])
    protected_file(private, MAX_POLICY)
    require(not (private.stat().st_mode & 0o077), "SSH identity must be mode 0600")
    args = build_command(policy, verb)
    env = {"PATH": "/usr/bin:/bin", "LANG": "C", "LC_ALL": "C", "HOME": "/nonexistent"}
    try:
        proc = runner(args, input=b"", stdout=subprocess.PIPE,
                      stderr=subprocess.PIPE, timeout=20, check=False, env=env)
    except (OSError, subprocess.TimeoutExpired) as exc:
        raise RemoteHold("restricted SSH command unavailable") from exc
    require(proc.returncode == 0, "restricted SSH command failed")
    require(isinstance(proc.stdout, bytes), "remote stdout not bytes")
    return evaluate(verb, proc.stdout, now=now or datetime.now(timezone.utc))


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("verb", choices=ALLOWED)
    args = parser.parse_args()
    try:
        policy = validate_policy(protected_file(POLICY, MAX_POLICY))
        report = collect(policy, args.verb)
        status = 0
    except (RemoteHold, OSError, TypeError, KeyError, ValueError) as exc:
        report = {"schema": REPORT, "status": "REJECTED", "deployable": False,
                  "reason": str(exc)[:180], "actions_executed": []}
        status = 2
    print(json.dumps(report, ensure_ascii=False, sort_keys=True, indent=2))
    return status


if __name__ == "__main__":
    raise SystemExit(main())
