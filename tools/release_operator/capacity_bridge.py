#!/usr/bin/env python3
"""BMS24 V1f restricted *read-only* capacity command.

Install this file and storage_preflight.py in a root-controlled directory next
to the existing read-only inventory command. The SSH forced-command wrapper
accepts only inventory or capacity, no shell parameters.

This entrypoint reads a *host-owned* fixed policy file; it has no CLI options,
network access, database access or write operations. Result is ALWAYS HOLD.
"""
from __future__ import annotations

import hashlib
import json
import os
from pathlib import Path
import stat
import sys

from storage_preflight import measure, StorageHold

SCHEMA = "bms24.release_operator.capacity_policy.v1f"
REPORT_SCHEMA = "bms24.release_operator.capacity_attestation.v1f"
POLICY_PATH = Path("/var/www/p706494/data/.bms24-inventory/capacity-policy.json")
EXPECTED_SITE = "/var/www/p706494/data/www/edu.kzn.mba"
MAX_POLICY_BYTES = 16384


class CapacityHold(ValueError):
    pass


def require(condition: bool, reason: str) -> None:
    if not condition:
        raise CapacityHold(reason)


def _pairs_unique(pairs):
    d = {}
    for key, val in pairs:
        if key in d:
            raise CapacityHold("duplicate policy JSON key")
        d[key] = val
    return d


def safe_policy_file(path: Path) -> bytes:
    # The policy is a host-side file, not a PR artifact, SSH argument or env.
    # Symlink parent directories are forbidden; policy must be locally owned
    # by root or the restricted SSH account and not writable by group/others.
    require(path.is_absolute(), "capacity policy must have absolute path")
    cursor = Path("/")
    for part in path.parts[1:]:
        cursor = cursor / part
        try:
            info = cursor.lstat()
        except OSError as exc:
            raise CapacityHold("capacity policy unavailable") from exc
        require(not stat.S_ISLNK(info.st_mode), "capacity policy path contains symlink")
        require((info.st_mode & 0o022) == 0, "capacity policy path is group/other writable")
    info = path.stat()
    require(stat.S_ISREG(info.st_mode), "capacity policy must be regular file")
    require(info.st_uid in (0, os.geteuid()), "capacity policy owner is not trusted")
    require(0 < info.st_size <= MAX_POLICY_BYTES, "capacity policy size invalid")
    flags = os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC
    fd = os.open(path, flags)
    try:
        opened = os.fstat(fd)
        require(opened.st_dev == info.st_dev and opened.st_ino == info.st_ino,
                "capacity policy changed while opening")
        require(opened.st_uid in (0, os.geteuid()) and
                not (opened.st_mode & 0o022) and
                opened.st_size <= MAX_POLICY_BYTES, "capacity policy permissions changed")
        result = os.read(fd, MAX_POLICY_BYTES + 1)
        require(0 < len(result) <= MAX_POLICY_BYTES, "capacity policy too large")
        return result
    finally:
        os.close(fd)


def validate_policy(data: bytes) -> dict:
    try:
        policy = json.loads(data.decode("utf-8"), object_pairs_hook=_pairs_unique)
    except (UnicodeError, json.JSONDecodeError) as exc:
        raise CapacityHold("malformed capacity policy") from exc
    require(isinstance(policy, dict) and
            set(policy) == {"schema", "target", "paths", "reservations"}, "unexpected capacity policy fields")
    require(policy["schema"] == SCHEMA and policy["target"] == "edu.kzn.mba",
            "incorrect server target/policy schema")
    paths = policy["paths"]
    reserve = policy["reservations"]
    require(isinstance(paths, dict) and set(paths) == {"site", "backup", "test"} and
            paths["site"] == EXPECTED_SITE,
            "unexpected production site directory")
    require(isinstance(reserve, dict) and set(reserve) == {"site", "backup", "test"},
            "incomplete capacity reservation")
    # In addition to storage_preflight's detailed bounds, do not accept
    # placeholders or a deliberate policy with all-zero reserve.
    for key in ("site", "backup", "test"):
        require(isinstance(paths[key], str) and paths[key].startswith("/") and
                "PRIVATE" not in paths[key] and "PLACEHOLDER" not in paths[key],
                "capacity paths must reference actual local directories")
        req = reserve[key]
        require(isinstance(req, dict) and set(req) == {"bytes", "inodes"} and
                type(req["bytes"]) is int and req["bytes"] > 0 and
                type(req["inodes"]) is int and req["inodes"] > 0,
                "capacity reserve must be explicitly nonzero")
    return policy


def audit(config_bytes: bytes, *, meter=measure) -> dict:
    policy = validate_policy(config_bytes)
    measured = meter(policy["paths"], policy["reservations"])
    require(isinstance(measured, dict) and measured.get("status") == "HOLD" and
            measured.get("deployable") is False and
            measured.get("actions_executed") == [], "measurement is not read-only")
    return {
        "schema": REPORT_SCHEMA, "status": "HOLD", "deployable": False,
        "target": "edu.kzn.mba",
        "policy_sha256": hashlib.sha256(config_bytes).hexdigest(),
        "measurement": measured,
        "server_identity_authenticated": False,
        "provider_quota_verified": False,
        "actions_executed": [],
        "blockers": [
            "SSH forced-command identity must be independently verified by the invoking operator",
            "No signed nonce / exact release binding; this report is NOT a production release approval",
            "Provider/account quota and real site backup/restore remain unverified",
        ]
    }


def main() -> int:
    # This process deliberately has no arguments or environment-driven paths.
    if len(sys.argv) != 1:
        print(json.dumps({"schema": REPORT_SCHEMA, "status": "REJECTED", "deployable": False,
                          "reason": "arguments not accepted", "actions_executed": []}))
        return 2
    try:
        result = audit(safe_policy_file(POLICY_PATH))
        code = 0
    except (CapacityHold, StorageHold, OSError, TypeError, ValueError) as exc:
        result = {"schema": REPORT_SCHEMA, "status": "REJECTED", "deployable": False,
                  "reason": str(exc)[:160], "actions_executed": []}
        code = 2
    print(json.dumps(result, ensure_ascii=False, sort_keys=True))
    return code


if __name__ == "__main__":
    raise SystemExit(main())
