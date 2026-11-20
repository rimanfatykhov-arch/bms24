"""Tests of V1e read-only storage accounting; never touch production."""
from __future__ import annotations

from collections import namedtuple
from pathlib import Path
import os
import sys
import tempfile
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parent))
from storage_preflight import StorageHold, measure, resolve_directory

DiskStat = namedtuple("DiskStat", "st_dev")
FsStat = namedtuple("FsStat", "f_bavail f_frsize f_favail")


class StoragePreflightTest(unittest.TestCase):
    def setUp(self):
        self.paths = {"site": "/synthetic/site", "backup": "/synthetic/backup",
                      "test": "/synthetic/test"}
        self.reserve = {name: {"bytes": 400, "inodes": 10} for name in self.paths}
        self.devices = {p: 123 for p in self.paths.values()}
        self.capacity = {123: FsStat(1000, 1, 100)}
        self.resolver = lambda path, *, name: Path(path)
        self.stat = lambda path: DiskStat(self.devices[str(path)])
        self.space = lambda path: self.capacity[self.devices[str(path)]]

    def run_probe(self):
        return measure(self.paths, self.reserve, path_resolver=self.resolver,
                       filesystem_stat=self.stat, filesystem_space=self.space)

    def test_shared_volume_does_not_triple_count_free_space(self):
        r = self.run_probe()
        self.assertEqual(r["status"], "HOLD")
        self.assertFalse(r["deployable"])
        self.assertTrue(r["paths_share_filesystem"])
        self.assertFalse(r["declared_reserve_met"])
        self.assertEqual(len(r["filesystems"]), 1)
        self.assertEqual(r["filesystems"][0]["reserved_bytes"], 1200)
        self.assertEqual(r["filesystems"][0]["free_bytes_available_to_process"], 1000)
        self.assertFalse(r["host_account_quota_verified"])
        self.assertFalse(r["backup_recovery_verified"])
        self.assertEqual(r["actions_executed"], [])

    def test_separate_volumes_aggregate_independently(self):
        for dev, name in enumerate(("site", "backup", "test"), start=123):
            self.devices[self.paths[name]] = dev
            self.capacity[dev] = FsStat(1000, 1, 100)
        r = self.run_probe()
        self.assertTrue(r["declared_reserve_met"])
        self.assertFalse(r["paths_share_filesystem"])
        self.assertEqual(len(r["filesystems"]), 3)
        self.assertEqual(r["status"], "HOLD")
        self.assertFalse(r["deployable"])

    def test_shared_volume_enough_capacity_still_not_authorized(self):
        self.capacity[123] = FsStat(1600, 1, 100)
        r = self.run_probe()
        self.assertTrue(r["declared_reserve_met"])
        self.assertFalse(r["deployable"])
        self.assertFalse(r["host_account_quota_verified"])

    def test_inodes_shared_pool_cannot_be_counted_three_times(self):
        self.capacity[123] = FsStat(1600, 1, 20)
        r = self.run_probe()
        self.assertTrue(r["filesystems"][0]["bytes_reservation_met"])
        self.assertFalse(r["filesystems"][0]["inodes_reservation_met"])
        self.assertFalse(r["declared_reserve_met"])

    def test_negative_bool_and_extra_fields_are_rejected(self):
        for bad in (-1, True, 10**14, "100"):
            self.reserve["site"]["bytes"] = bad
            with self.subTest(value=bad), self.assertRaises(StorageHold):
                self.run_probe()
        self.reserve["site"]["bytes"] = 100
        self.reserve["site"]["secret"] = "SHOULD_NOT_BE_ACCEPTED"
        with self.assertRaises(StorageHold):
            self.run_probe()

    def test_unknown_or_missing_paths_are_rejected(self):
        self.paths.pop("test")
        with self.assertRaises(StorageHold):
            self.run_probe()
        self.paths["test"] = "/synthetic/test"
        self.paths["extra"] = "/synthetic/extra"
        with self.assertRaises(StorageHold):
            self.run_probe()

    def test_capacity_drift_for_same_device_rejected(self):
        sequence = iter([FsStat(1000, 1, 100), FsStat(900, 1, 100)])
        with self.assertRaisesRegex(StorageHold, "changed during measurement"):
            measure(self.paths, self.reserve, path_resolver=self.resolver,
                    filesystem_stat=self.stat, filesystem_space=lambda path: next(sequence))

    def test_real_path_probe_never_writes_and_returns_hold(self):
        with tempfile.TemporaryDirectory() as tmp:
            roots = {name: str(Path(tmp) / name) for name in ("site", "backup", "test")}
            for path in roots.values():
                Path(path).mkdir()
            r = measure(roots, {name: {"bytes": 1, "inodes": 1} for name in roots})
            self.assertEqual(r["status"], "HOLD")
            self.assertEqual(r["actions_executed"], [])
            self.assertTrue(r["paths_share_filesystem"])
            self.assertEqual(set(os.listdir(tmp)), set(roots))

    def test_symlink_directory_denied(self):
        with tempfile.TemporaryDirectory() as tmp:
            target = Path(tmp) / "real"
            target.mkdir()
            shortcut = Path(tmp) / "link"
            shortcut.symlink_to(target, target_is_directory=True)
            with self.assertRaisesRegex(StorageHold, "symlink"):
                resolve_directory(str(shortcut), name="site")
            with self.assertRaises(StorageHold):
                resolve_directory("relative/path", name="site")

    def test_unavailable_directory_fails_closed(self):
        with self.assertRaises(StorageHold):
            resolve_directory("/nonexistent-bms24-release-dir-v1e", name="site")


if __name__ == "__main__":
    unittest.main()
