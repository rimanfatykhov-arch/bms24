"""V1g pinned SSH read-only client tests (mock subprocess, NEVER real SSH)."""
from __future__ import annotations

from datetime import datetime, timedelta, timezone
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parent))
from readonly_ssh_client import (
    ALLOWED, RemoteHold, SCHEMA, build_command, collect, evaluate, validate_policy
)

NOW = datetime(2026, 11, 20, 9, 0, 0, tzinfo=timezone.utc)
STAMP = "2026-11-20T08:59:00+00:00"
CAP_STAMP = "2026-11-20T08:59:00Z"


def policy():
    return {
        "schema": SCHEMA, "target": "edu.kzn.mba",
        "host": "restricted.example.org", "port": 22, "user": "bmsinventory",
        "identity_file": "/etc/bms24-release-operator/keys/readonly",
        "known_hosts_file": "/etc/bms24-release-operator/known_hosts",
        "known_hosts_sha256": hashlib.sha256(b"pinned test known_hosts").hexdigest(),
    }


def inventory():
    return {
        "captured_utc": STAMP, "read_only": True, "moodle_bootstrap": False,
        "php_sapi": "cli", "php_cli": "8.1.34",
        "server": {"version": "8.0.46-37", "engine": "Percona"},
        "code": {"moodle": {"release": "4.1.22"}},
        "installed_plugin_versions": {
            "local_kbacompany": "2026111100",
            "local_kbacorporate": "2026111100",
        },
    }


def capacity():
    return {
        "schema": "bms24.release_operator.capacity_attestation.v1f",
        "status": "HOLD", "deployable": False, "target": "edu.kzn.mba",
        "server_identity_authenticated": False, "provider_quota_verified": False,
        "actions_executed": [],
        "measurement": {
            "schema": "bms24.release_operator.storage_report.v1e",
            "status": "HOLD", "deployable": False,
            "captured_at": CAP_STAMP, "declared_reserve_met": True,
            "filesystems": [{"filesystem_device": 123}], "actions_executed": [],
        },
    }


class SSHReadClientTest(unittest.TestCase):
    def test_inventory_without_claiming_go(self):
        d = evaluate("inventory", json.dumps(inventory()).encode(), now=NOW)
        self.assertEqual(d["status"], "HOLD")
        self.assertFalse(d["deployable"])
        self.assertFalse(d["server_runtime_identity_independently_verified"])
        self.assertEqual(d["observed_plugin_versions"]["local_kbacompany"], "2026111100")
        self.assertEqual(d["actions_executed"], [])

    def test_capacity_reports_declared_reserve_but_not_quota_or_go(self):
        d = evaluate("capacity", json.dumps(capacity()).encode(), now=NOW)
        self.assertEqual(d["status"], "HOLD")
        self.assertTrue(d["declared_reserve_met"])
        self.assertFalse(d["provider_quota_verified"])
        self.assertFalse(d["verified_live_backup_restore"])

    def test_bad_server_attestation_rejected(self):
        for key, value in (("deployable", True), ("status", "PASS"),
                           ("provider_quota_verified", True),
                           ("server_identity_authenticated", True),
                           ("actions_executed", ["deploy"])):
            c = capacity()
            c[key] = value
            with self.subTest(key=key), self.assertRaises(RemoteHold):
                evaluate("capacity", json.dumps(c).encode(), now=NOW)

    def test_invalid_meter_false_go_and_missing_capacity_evidence_rejected(self):
        for key, bad in (("status", "PASS"), ("deployable", True),
                         ("actions_executed", ["backup"]),
                         ("declared_reserve_met", "true"),
                         ("filesystems", [])):
            c = capacity()
            c["measurement"][key] = bad
            with self.subTest(key=key), self.assertRaises(RemoteHold):
                evaluate("capacity", json.dumps(c).encode(), now=NOW)

    def test_stale_and_future_responses_rejected(self):
        i = inventory()
        i["captured_utc"] = "2026-11-20T08:50:00+00:00"
        with self.assertRaises(RemoteHold):
            evaluate("inventory", json.dumps(i).encode(), now=NOW)
        c = capacity()
        c["measurement"]["captured_at"] = "2026-11-20T09:01:00Z"
        with self.assertRaises(RemoteHold):
            evaluate("capacity", json.dumps(c).encode(), now=NOW)

    def test_inventory_requires_read_only_cli_and_versions(self):
        for field, bad in (("read_only", False),
                           ("moodle_bootstrap", True), ("php_sapi", "fpm")):
            i = inventory()
            i[field] = bad
            with self.subTest(field=field), self.assertRaises(RemoteHold):
                evaluate("inventory", json.dumps(i).encode(), now=NOW)
        i = inventory()
        del i["installed_plugin_versions"]["local_kbacompany"]
        with self.assertRaises(RemoteHold):
            evaluate("inventory", json.dumps(i).encode(), now=NOW)

    def test_unknown_verb_and_duplicates_rejected(self):
        for bad in ("deploy", "capacity; touch /tmp/pwned", "backup", "sh"):
            with self.assertRaises(RemoteHold):
                build_command(policy(), bad)
        with self.assertRaises(RemoteHold):
            evaluate("inventory", b'{"read_only":true,"read_only":true}', now=NOW)

    def test_ssh_command_has_strict_host_key_and_no_forwarding(self):
        cmd = build_command(policy(), "inventory")
        self.assertEqual(cmd[0], "/usr/bin/ssh")
        self.assertEqual(cmd[-1], "inventory")
        self.assertIn("StrictHostKeyChecking=yes", cmd)
        self.assertIn("UpdateHostKeys=no", cmd)
        self.assertIn("GlobalKnownHostsFile=/dev/null", cmd)
        self.assertIn("IdentitiesOnly=yes", cmd)
        self.assertIn("IdentityAgent=none", cmd)
        self.assertIn("ClearAllForwardings=yes", cmd)
        self.assertIn("BatchMode=yes", cmd)
        self.assertIn("UserKnownHostsFile=/etc/bms24-release-operator/known_hosts", cmd)
        self.assertIn("bmsinventory@restricted.example.org", cmd)
        self.assertNotIn("sh", cmd)

    def test_policy_rejects_root_option_injection_and_bad_target(self):
        for key, bad in (("target", "dev"), ("user", "root"),
                         ("host", "-oProxyCommand=cat"), ("port", True),
                         ("known_hosts_sha256", "wrong"), ("identity_file", "relative")):
            p = policy()
            p[key] = bad
            with self.subTest(key=key), self.assertRaises(RemoteHold):
                validate_policy(json.dumps(p).encode())

    def test_policy_duplicate_fields_rejected(self):
        with self.assertRaises(RemoteHold):
            validate_policy(b'{"schema":"a","schema":"b"}')

    def test_client_never_calls_real_ssh_in_tests(self):
        seen = []
        class Result:
            returncode = 0
            stdout = json.dumps(inventory()).encode()
        def fake_runner(argv, **kwargs):
            seen.append((argv, kwargs))
            return Result()
        with patch("readonly_ssh_client.protected_file", side_effect=[b"pinned test known_hosts", b"key"]):
            # fs paths aren't on test machine: patch just the fstat privacy check
            with patch("readonly_ssh_client.Path.stat") as fake_stat:
                fake_stat.return_value.st_mode = 0o100600
                r = collect(policy(), "inventory", runner=fake_runner, now=NOW)
        self.assertEqual(r["status"], "HOLD")
        self.assertEqual(len(seen), 1)
        self.assertEqual(seen[0][0][-1], "inventory")
        self.assertEqual(seen[0][1]["input"], b"")
        self.assertEqual(seen[0][1]["env"]["HOME"], "/nonexistent")
        self.assertNotIn("SSH_AUTH_SOCK", seen[0][1]["env"])
        self.assertEqual(seen[0][1]["timeout"], 20)

    def test_failed_remote_command_does_not_return_hold_as_success(self):
        class Result:
            returncode = 255
            stdout = b""
        with patch("readonly_ssh_client.protected_file", side_effect=[b"pinned test known_hosts", b"key"]):
            with patch("readonly_ssh_client.Path.stat") as stat:
                stat.return_value.st_mode = 0o100600
                with self.assertRaises(RemoteHold):
                    collect(policy(), "inventory", runner=lambda *a, **k: Result(), now=NOW)

    def test_report_does_not_include_raw_business_metadata(self):
        i = inventory()
        i["sensitive_business_notes"] = "SHOULD_NOT_OUTPUT"
        r = evaluate("inventory", json.dumps(i).encode(), now=NOW)
        self.assertNotIn("SHOULD_NOT_OUTPUT", json.dumps(r))


if __name__ == "__main__":
    unittest.main()
