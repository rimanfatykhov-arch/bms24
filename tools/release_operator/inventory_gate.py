#!/usr/bin/env python3
"""V1b: fail-closed validation of a read-only server inventory declaration.

A supplied JSON snapshot is NOT authenticated server evidence. This validator
has no SSH/DB/network access and ALWAYS returns HOLD when input is consistent.
"""
from __future__ import annotations

import argparse
from datetime import datetime, timezone, timedelta
import json
from pathlib import Path
import re

from artifact_gate import ArtifactHold, read_json
from preflight import InvalidRequest, validate

HEX64 = re.compile(r"[0-9a-f]{64}\Z")


class InventoryHold(ValueError):
    pass


def require(ok: bool, reason: str) -> None:
    if not ok:
        raise InventoryHold(reason)


def exact(d, fields: set[str], label: str):
    require(isinstance(d, dict) and set(d) == fields, "invalid " + label + " fields")
    return d


def positive(value) -> bool:
    return type(value) is int and value >= 0


def parse_utc(value: str) -> datetime:
    require(isinstance(value, str) and value.endswith("Z") and
            re.fullmatch(r"\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ", value) is not None,
            "inventory timestamp must be exact UTC seconds")
    try:
        return datetime.fromisoformat(value[:-1] + "+00:00")
    except ValueError as exc:
        raise InventoryHold("invalid inventory timestamp") from exc


def inspect(request: dict, baseline: dict, inventory: dict, now: datetime) -> dict:
    try:
        request = validate(request)
    except InvalidRequest as exc:
        raise InventoryHold("invalid V0 request: " + str(exc)) from exc
    p = exact(baseline, {"schema", "target", "bms_runtime_tree_sha256",
                         "corporate_runtime_tree_sha256", "corporate_plugin_version",
                         "moodle_version", "php_major_minor", "db_family",
                         "min_free_bytes", "safety_margin_bytes", "min_free_inodes"}, "baseline policy")
    require(p["schema"] == "bms24.release_operator.baseline_policy.v1b", "unknown baseline policy")
    require(p["target"] == "production" and request["environment"] == "production",
            "server inventory must be for production in this profile")
    for key in ("bms_runtime_tree_sha256", "corporate_runtime_tree_sha256"):
        require(isinstance(p[key], str) and HEX64.fullmatch(p[key]) is not None,
                "missing runtime-tree pin: " + key)
    require(type(p["corporate_plugin_version"]) is int and
            p["corporate_plugin_version"] > 0, "invalid Corporate version")
    require(p["moodle_version"] == "4.1.22" and p["php_major_minor"] == "8.1" and
            p["db_family"] == "Percona", "unexpected target runtime profile")
    for key in ("min_free_bytes", "safety_margin_bytes", "min_free_inodes"):
        require(positive(p[key]), "invalid storage threshold")
    require(p["safety_margin_bytes"] >= 512 * 1024 * 1024,
            "unsafe storage margin below 512 MiB")

    s = exact(inventory, {"schema", "target", "captured_at", "software", "components",
                          "database", "services", "storage"}, "server inventory")
    require(s["schema"] == "bms24.release_operator.server_inventory.v1b", "unknown inventory schema")
    require(s["target"] == p["target"], "inventory target mismatch")
    require(now.tzinfo is not None, "trusted clock must be timezone-aware")
    stamp = parse_utc(s["captured_at"])
    age = now.astimezone(timezone.utc) - stamp
    require(timedelta(seconds=-30) <= age <= timedelta(minutes=5),
            "server inventory is expired or dated in the future")
    software = exact(s["software"], {"moodle_version", "php_version", "database_family",
                                      "database_version"}, "software")
    require(software["moodle_version"] == p["moodle_version"] and
            isinstance(software["php_version"], str) and
            software["php_version"].startswith(p["php_major_minor"] + ".") and
            software["database_family"] == p["db_family"] and
            re.fullmatch(r"\d+\.\d+\.\d+(?:-\d+)?", str(software["database_version"])) is not None,
            "unexpected software runtime identity")
    comp = exact(s["components"], {"bms_plugin_version", "bms_runtime_tree_sha256",
                                    "corporate_plugin_version", "corporate_runtime_tree_sha256"}, "components")
    require(type(comp["bms_plugin_version"]) is int and
            comp["bms_plugin_version"] == request["production_baseline"]["plugin_version"],
            "BMS installed plugin version drift")
    require(comp["bms_runtime_tree_sha256"] == p["bms_runtime_tree_sha256"] and
            comp["corporate_runtime_tree_sha256"] == p["corporate_runtime_tree_sha256"] and
            type(comp["corporate_plugin_version"]) is int and
            comp["corporate_plugin_version"] == p["corporate_plugin_version"],
            "installed runtime tree/Corporate baseline drift")
    db = exact(s["database"], {"bms_db_version", "daily_report_tables_present"}, "database")
    require(type(db["bms_db_version"]) is int and
            db["bms_db_version"] == request["production_baseline"]["plugin_version"],
            "BMS database version drift")
    require(db["daily_report_tables_present"] is False,
            "expected R0 baseline has unexpected daily reports tables")
    services = exact(s["services"], {"maintenance_enabled", "cron_expected_enabled",
                                     "release_lock_active"}, "services")
    require(type(services["maintenance_enabled"]) is bool and
            type(services["cron_expected_enabled"]) is bool and
            type(services["release_lock_active"]) is bool, "invalid service states")
    require(services["release_lock_active"] is False, "another release operator owns the lock")
    storage = exact(s["storage"], {"free_bytes", "free_inodes"}, "storage")
    require(positive(storage["free_bytes"]) and positive(storage["free_inodes"]),
            "invalid storage inventory")
    threshold = p["min_free_bytes"] + p["safety_margin_bytes"]
    has_capacity = (storage["free_bytes"] >= threshold and
                    storage["free_inodes"] >= p["min_free_inodes"])
    blockers = [
        "Inventory is a self-declared JSON snapshot; server identity/transport is NOT authenticated",
        "No continuous writer pause, fresh verified full backup/restore or tested DDL recovery",
        "No protected execution capability or owner authorization for a release window",
    ]
    if not has_capacity:
        blockers.insert(0, "Insufficient free bytes/inodes for configured release reserve")
    if services["maintenance_enabled"] or not services["cron_expected_enabled"]:
        blockers.insert(0, "Unusual maintenance/cron state requires an independent operator review")
    return {
        "schema": "bms24.release_operator.inventory_report.v1b",
        "status": "HOLD",
        "deployable": False,
        "declared_baseline_matches_profile": True,
        "server_origin_authenticated": False,
        "declared_snapshot_fresh": True,
        "declared_capacity_margin_met": has_capacity,
        "available_bytes": storage["free_bytes"],
        "required_bytes_with_margin": threshold,
        "actions_executed": [],
        "blockers": blockers,
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--request", type=Path, required=True)
    parser.add_argument("--baseline-policy", type=Path, required=True)
    parser.add_argument("--inventory", type=Path, required=True)
    parser.add_argument("--report", type=Path, required=True)
    args = parser.parse_args()
    try:
        result = inspect(read_json(args.request, 1024 * 1024),
                         read_json(args.baseline_policy, 1024 * 1024),
                         read_json(args.inventory, 1024 * 1024), datetime.now(timezone.utc))
        status = 0
    except (InventoryHold, ArtifactHold, OSError, TypeError, KeyError) as exc:
        result = {"schema": "bms24.release_operator.inventory_report.v1b",
                  "status": "REJECTED", "deployable": False,
                  "reason": str(exc)[:200], "actions_executed": []}
        status = 2
    args.report.parent.mkdir(parents=True, exist_ok=True)
    args.report.write_text(json.dumps(result, ensure_ascii=False, indent=2) + "\n",
                           encoding="utf-8")
    print("BMS24 inventory gate: {} (NO SERVER ACTION)".format(result["status"]))
    return status


if __name__ == "__main__":
    raise SystemExit(main())
