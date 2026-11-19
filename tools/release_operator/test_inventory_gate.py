"""V1b inventory tests: synthetic declarations only, never real server access."""
from __future__ import annotations

import copy
from datetime import datetime, timezone, timedelta
from pathlib import Path
import sys
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parent))
from inventory_gate import inspect, InventoryHold

NOW = datetime(2026, 11, 19, 19, 0, 0, tzinfo=timezone.utc)
BMS_TREE = "eff04f92f29bc553afba3ba9b8a7fb053a2b6624a141bfdb8cf1f9cc6cbd6fce"
CORP_TREE = "f6fc849adbad8338cf8ab8d6c69a16323782281949e85d10ccc4ca9df2840ac7"


def fixture():
    request = {
        "schema": "bms24.release_operator.request.v0",
        "release_id": "BMS24-R1R2-20261119",
        "repository": "rimanfatykhov-arch/bms24",
        "environment": "production", "mode": "read_only_preflight",
        "production_baseline": {"source_sha": "9" * 40, "plugin_version": 2026111100},
        "candidate": {"pr_number": 124, "head_sha": "a" * 40, "ci_checkout_sha": "b" * 40,
                      "plugin_version": 2026111601, "zip_sha256": "c" * 64,
                      "manifest_sha256": "d" * 64},
        "migration": {"required": True, "expected_new_tables": 5,
                      "recovery": "feature_off_forward_fix_or_separately_approved_restore"},
        "authorization": {"owner_approval": "NOT_GRANTED", "deployment_window": None},
    }
    policy = {
        "schema": "bms24.release_operator.baseline_policy.v1b",
        "target": "production", "bms_runtime_tree_sha256": BMS_TREE,
        "corporate_runtime_tree_sha256": CORP_TREE,
        "corporate_plugin_version": 2026111100,
        "moodle_version": "4.1.22", "php_major_minor": "8.1", "db_family": "Percona",
        "min_free_bytes": 4731174912, "safety_margin_bytes": 536870912,
        "min_free_inodes": 10000,
    }
    observed = {
        "schema": "bms24.release_operator.server_inventory.v1b",
        "target": "production",
        "captured_at": NOW.strftime("%Y-%m-%dT%H:%M:%SZ"),
        "software": {"moodle_version": "4.1.22", "php_version": "8.1.34",
                     "database_family": "Percona", "database_version": "8.0.46-37"},
        "components": {"bms_plugin_version": 2026111100,
                       "bms_runtime_tree_sha256": BMS_TREE,
                       "corporate_plugin_version": 2026111100,
                       "corporate_runtime_tree_sha256": CORP_TREE},
        "database": {"bms_db_version": 2026111100,
                     "daily_report_tables_present": False},
        "services": {"maintenance_enabled": False, "cron_expected_enabled": True,
                     "release_lock_active": False},
        "storage": {"free_bytes": 6_000_000_000, "free_inodes": 20_000},
    }
    return request, policy, observed


class InventoryGateTest(unittest.TestCase):
    def setUp(self):
        self.request, self.policy, self.observed = fixture()

    def check(self, now=NOW):
        return inspect(self.request, self.policy, self.observed, now)

    def reject(self, now=NOW):
        with self.assertRaises(InventoryHold):
            self.check(now)

    def test_consistent_fresh_snapshot_remains_unverified_hold(self):
        result = self.check()
        self.assertEqual(result["status"], "HOLD")
        self.assertFalse(result["deployable"])
        self.assertFalse(result["server_origin_authenticated"])
        self.assertTrue(result["declared_snapshot_fresh"])
        self.assertTrue(result["declared_capacity_margin_met"])
        self.assertEqual(result["actions_executed"], [])

    def test_baseline_version_and_runtime_hash_must_match(self):
        for path, field, bad in [
            ("components", "bms_plugin_version", 2026111601),
            ("components", "bms_runtime_tree_sha256", "0" * 64),
            ("components", "corporate_plugin_version", 2026111101),
            ("components", "corporate_runtime_tree_sha256", "a" * 64),
            ("database", "bms_db_version", 2026111601),
            ("database", "daily_report_tables_present", True),
        ]:
            self.request, self.policy, self.observed = fixture()
            self.observed[path][field] = bad
            with self.subTest(field=field):
                self.reject()

    def test_stale_or_future_snapshot_is_rejected(self):
        self.reject(now=NOW + timedelta(minutes=6))
        self.reject(now=NOW - timedelta(minutes=1))
        self.observed["captured_at"] = "2026-11-19T19:00:00+00:00"
        self.reject()

    def test_unusual_software_or_target_rejected(self):
        for field, bad in [("moodle_version", "4.2.0"),
                           ("php_version", "8.4.1"), ("database_family", "MySQL"),
                           ("database_version", "unknown")]:
            self.request, self.policy, self.observed = fixture()
            self.observed["software"][field] = bad
            with self.subTest(field=field):
                self.reject()
        self.request, self.policy, self.observed = fixture()
        self.observed["target"] = "dev"
        self.reject()

    def test_inadequate_space_or_inodes_stays_hold_not_pass(self):
        self.observed["storage"]["free_bytes"] = 4767711232
        r = self.check()
        self.assertEqual(r["status"], "HOLD")
        self.assertFalse(r["declared_capacity_margin_met"])
        self.assertGreater(r["required_bytes_with_margin"], r["available_bytes"])
        self.request, self.policy, self.observed = fixture()
        self.observed["storage"]["free_inodes"] = 9999
        self.assertFalse(self.check()["declared_capacity_margin_met"])

    def test_margin_cannot_be_weakened_below_minimum(self):
        self.policy["safety_margin_bytes"] = 1
        self.reject()
        self.request, self.policy, self.observed = fixture()
        self.policy["min_free_bytes"] = -1
        self.reject()

    def test_active_lock_rejected_and_maintenance_or_cron_flagged(self):
        self.observed["services"]["release_lock_active"] = True
        self.reject()
        self.request, self.policy, self.observed = fixture()
        self.observed["services"]["maintenance_enabled"] = True
        self.assertTrue(any("maintenance" in b for b in self.check()["blockers"]))
        self.request, self.policy, self.observed = fixture()
        self.observed["services"]["cron_expected_enabled"] = False
        self.assertTrue(any("maintenance" in b for b in self.check()["blockers"]))

    def test_unknown_fields_or_credentials_rejected(self):
        self.observed["ssh_password"] = "not-allowed"
        self.reject()
        self.request, self.policy, self.observed = fixture()
        self.policy["allow_deploy"] = True
        self.reject()
        self.request, self.policy, self.observed = fixture()
        self.observed["software"]["moodle_root"] = "/secret/path"
        self.reject()

    def test_invalid_types_are_rejected(self):
        self.observed["storage"]["free_bytes"] = True
        self.reject()
        self.request, self.policy, self.observed = fixture()
        self.observed["components"]["bms_plugin_version"] = True
        self.reject()
        self.request, self.policy, self.observed = fixture()
        self.observed["services"]["maintenance_enabled"] = "false"
        self.reject()

    def test_invalid_owner_approval_not_accepted(self):
        self.request["authorization"]["owner_approval"] = "GRANTED"
        self.reject()


if __name__ == "__main__":
    unittest.main()
