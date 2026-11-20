#!/usr/bin/env python3
"""BMS24 V1e: read-only, fail-closed storage preflight for migration rehearsals.

Checks REAL filesystem space/inodes by filesystem identity, not by summing
multiple paths which may point to the SAME underlying volume. It cannot detect
provider quotas, thin provisioning, external mounts or transient usage. Every
report remains HOLD. No filesystem writes, no SSH, no backup or deployment.
"""
from __future__ import annotations

import argparse
from datetime import datetime, timezone
import json
import os
from pathlib import Path
import stat
from typing import Any

SCHEMA = "bms24.release_operator.storage_report.v1e"
MAX_RESERVE = 10 ** 13  # 10 TB per volume sanity limit.


class StorageHold(ValueError):
    pass


def require(ok: bool, why: str) -> None:
    if not ok:
        raise StorageHold(why)


def resolve_directory(value: str, *, name: str) -> Path:
    require(isinstance(value, str) and value.startswith("/") and "\x00" not in value,
            name + " must be an absolute path")
    path = Path(value)
    require(".." not in path.parts and "." not in path.parts,
            name + " cannot contain relative path traversal")
    # Reject every symlink component, including a symlink to a directory.
    # This intentionally makes administrators explicitly select the actual
    # resolved location; it never follows an unexpected mount alias.
    current = Path("/")
    for component in path.parts[1:]:
        current = current / component
        try:
            meta = current.lstat()
        except OSError as exc:
            raise StorageHold(name + " path not accessible") from exc
        require(not stat.S_ISLNK(meta.st_mode), name + " contains a symlink")
    require(path.is_dir(), name + " must be an existing directory")
    return path


def parse_reserve(value: Any, name: str) -> int:
    require(type(value) is int and 0 <= value <= MAX_RESERVE, "invalid reservation for " + name)
    return value


def measure(paths: dict[str, str], reservations: dict[str, dict[str, int]],
            *, path_resolver=resolve_directory, filesystem_stat=os.stat,
            filesystem_space=os.statvfs) -> dict:
    expected = {"site", "backup", "test"}
    require(isinstance(paths, dict) and set(paths) == expected, "site/backup/test directories required")
    require(isinstance(reservations, dict) and set(reservations) == expected,
            "site/backup/test reserved bytes and inodes required")
    resolved = {}
    volumes: dict[int, dict] = {}
    aggregated: dict[int, dict] = {}
    for name in ("site", "backup", "test"):
        policy = reservations[name]
        require(isinstance(policy, dict) and set(policy) == {"bytes", "inodes"},
                "reservation fields must be exactly bytes and inodes")
        bytes_needed = parse_reserve(policy["bytes"], name + ".bytes")
        inodes_needed = parse_reserve(policy["inodes"], name + ".inodes")
        root = path_resolver(paths[name], name=name)
        metadata = filesystem_stat(root)
        fs = filesystem_space(root)
        dev = metadata.st_dev
        require(type(dev) is int, "invalid filesystem identifier")
        free_bytes = int(fs.f_bavail) * int(fs.f_frsize)
        free_inodes = int(fs.f_favail)
        require(free_bytes >= 0 and free_inodes >= 0, "invalid filesystem capacity")
        volume = volumes.get(dev)
        if volume is None:
            volume = {
                "filesystem_device": dev,
                "free_bytes_available_to_process": free_bytes,
                "free_inodes_available_to_process": free_inodes,
                "paths": [],
            }
            volumes[dev] = volume
            aggregated[dev] = {"reserved_bytes": 0, "reserved_inodes": 0}
        else:
            # A mount appearing to be the same device MUST NOT claim different
            # availability measurements as two independent pools.
            require(volume["free_bytes_available_to_process"] == free_bytes and
                    volume["free_inodes_available_to_process"] == free_inodes,
                    "same-device capacity changed during measurement; retry later")
        volume["paths"].append(name)
        aggregated[dev]["reserved_bytes"] += bytes_needed
        aggregated[dev]["reserved_inodes"] += inodes_needed
        require(aggregated[dev]["reserved_bytes"] <= 3 * MAX_RESERVE and
                aggregated[dev]["reserved_inodes"] <= 3 * MAX_RESERVE,
                "aggregate reservation over limit")
        resolved[name] = {"filesystem_device": dev,
                          "required_bytes": bytes_needed, "required_inodes": inodes_needed}
    groups = []
    has_space = True
    for dev, vol in sorted(volumes.items()):
        reserve = aggregated[dev]
        bytes_ok = vol["free_bytes_available_to_process"] >= reserve["reserved_bytes"]
        inodes_ok = vol["free_inodes_available_to_process"] >= reserve["reserved_inodes"]
        has_space &= bytes_ok and inodes_ok
        groups.append({**vol, **reserve,
                       "bytes_reservation_met": bytes_ok,
                       "inodes_reservation_met": inodes_ok})
    same_pool = any(len(v["paths"]) > 1 for v in groups)
    return {
        "schema": SCHEMA,
        "status": "HOLD",
        "deployable": False,
        "captured_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "paths": resolved,
        "filesystems": groups,
        "declared_reserve_met": bool(has_space),
        "paths_share_filesystem": same_pool,
        "host_account_quota_verified": False,
        "thin_provisioning_verified": False,
        "backup_recovery_verified": False,
        "writer_pause_verified": False,
        "server_identity_authenticated": False,
        "actions_executed": [],
        "blockers": [
            *([] if has_space else ["Insufficient filesystem bytes/inodes for aggregated reserves"]),
            "Provider-level account quota / inode quota not measured; df/statvfs cannot replace provider receipt",
            "No authenticated provenance for this local report or approved release window",
            "No fresh verified backup and isolated restore, continuous writer pause or DDL-safe executor",
        ],
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--site", required=True)
    parser.add_argument("--backup", required=True)
    parser.add_argument("--test", required=True)
    parser.add_argument("--site-bytes", type=int, required=True)
    parser.add_argument("--backup-bytes", type=int, required=True)
    parser.add_argument("--test-bytes", type=int, required=True)
    parser.add_argument("--site-inodes", type=int, required=True)
    parser.add_argument("--backup-inodes", type=int, required=True)
    parser.add_argument("--test-inodes", type=int, required=True)
    # No arbitrary report output path: stdout can be captured by trusted caller.
    args = parser.parse_args()
    try:
        report = measure(
            {"site": args.site, "backup": args.backup, "test": args.test},
            {name: {"bytes": getattr(args, name + "_bytes"),
                    "inodes": getattr(args, name + "_inodes")}
             for name in ("site", "backup", "test")},
        )
        code = 0
    except (StorageHold, OSError, ValueError) as exc:
        report = {"schema": SCHEMA, "status": "REJECTED", "deployable": False,
                  "reason": str(exc)[:180], "actions_executed": []}
        code = 2
    print(json.dumps(report, sort_keys=True, ensure_ascii=False, indent=2))
    return code


if __name__ == "__main__":
    raise SystemExit(main())
