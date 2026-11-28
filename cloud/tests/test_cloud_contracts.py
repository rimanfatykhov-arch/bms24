"""Offline tests for the CLOUD-1a contract validator (stdlib unittest only).

Run: python -m unittest discover -s cloud/tests -v
These tests prove contract and fixture consistency only; they are not runtime,
restore (G3) or application ACL evidence.
"""
import contextlib
import copy
import hashlib
import io
import json
import os
import shutil
import sys
import tempfile
import unittest
import zipfile

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(os.path.dirname(HERE), "tools"))
import cloud_contracts as cc  # noqa: E402


def run_cli(*argv):
    out, err = io.StringIO(), io.StringIO()
    with contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
        code = cc.main(list(argv))
    return code, out.getvalue(), err.getvalue()


class ContextMixin(object):
    @classmethod
    def setUpClass(cls):
        cls.ctx = cc.build_context(cc.REPO_ROOT)
        cls.fixtures = cc.load_fixtures(cc.FIXTURES)

    def codes_for(self, payload, catalog=None):
        report = cc.Report()
        cc.check_payload(payload, self.ctx, report, "test", catalog)
        return report.codes()

    def base_payload(self):
        return copy.deepcopy(self.fixtures["P01_hybrid_ab"]["payload"])


class SuiteTests(ContextMixin, unittest.TestCase):
    def test_validate_exit_zero(self):
        code, out, _ = run_cli("validate")
        self.assertEqual(code, cc.EXIT_OK, out)
        self.assertIn("RESULT OK", out)

    def test_contracts_clean(self):
        report = cc.Report()
        cc.check_contracts(self.ctx, report)
        self.assertEqual(report.lines(), [])

    def test_every_fixture_meets_expectation(self):
        report = cc.Report()
        results = cc.run_fixtures(self.ctx, self.fixtures, report)
        self.assertTrue(results)
        for row in results:
            self.assertTrue(row["pass"], row)

    def test_negatives_fail_with_exactly_expected_codes(self):
        for fid, data in sorted(self.fixtures.items()):
            if data["_kind"] != "negative":
                continue
            catalog = cc.apply_patch(self.ctx["catalog"], data["catalog"]) if "catalog" in data else None
            with self.subTest(fixture=fid):
                self.assertEqual(self.codes_for(data["payload"], catalog), sorted(data["expect"]["violations"]))

    def test_required_negative_coverage(self):
        wanted = {"INV_CROSS_WORKSPACE_REFERENCE", "INV_IMMUTABLE_FIELD", "INV_DANGLING_REFERENCE", "SCHEMA_ENUM",
                  "INV_LEGACY_MAPPING_AMBIGUOUS", "INV_DEPENDENCY_CYCLE", "INV_BUNDLE_CYCLE", "INV_IDEMPOTENCY_CONFLICT",
                  "INV_PROVENANCE_MISSING", "INV_RAW_SECRET"}
        covered = set()
        for data in self.fixtures.values():
            covered |= set(data["expect"].get("violations", []))
        self.assertFalse(wanted - covered)

    def test_json_output_is_stable(self):
        first = run_cli("--json", "validate")
        second = run_cli("--json", "validate")
        self.assertEqual(first, second)
        doc = json.loads(first[1])
        self.assertEqual(doc["exit_code"], 0)
        self.assertEqual(doc["validator_version"], cc.VALIDATOR_VERSION)


class SemanticTests(ContextMixin, unittest.TestCase):
    def test_shared_instance_must_not_own_workspace(self):
        payload = self.base_payload()
        payload["state"]["application_instances"][0]["workspace_uuid"] = payload["state"]["workspaces"][0]["workspace_uuid"]
        self.assertIn("INV_ISOLATION_RULE", self.codes_for(payload))

    def test_job_idempotency_conflict_within_registry(self):
        payload = self.base_payload()
        job = dict(payload["state"]["provisioning_jobs"][0])
        job["job_uuid"] = "40000000-000b-4000-8000-000000000099"
        job["request_hash"] = hashlib.sha256(b"different").hexdigest()
        payload["state"]["provisioning_jobs"].append(job)
        audit = dict(payload["state"]["audit_events"][0], audit_uuid="50000000-0000-4000-8000-000000000099",
                     target_id=job["job_uuid"], target_type="provisioning_job", workspace_uuid=job["workspace_uuid"])
        payload["state"]["audit_events"].append(audit)
        self.assertEqual(self.codes_for(payload), ["INV_IDEMPOTENCY_CONFLICT"])

    def test_private_key_value_detected(self):
        payload = self.base_payload()
        payload["state"]["customers"][0]["metadata"] = {"note": "-----BEGIN RSA PRIVATE KEY-----"}
        self.assertEqual(self.codes_for(payload), ["INV_RAW_SECRET"])

    def test_secretref_is_allowed(self):
        self.assertEqual(self.codes_for(self.base_payload()), [])

    def test_raw_credential_reference_rejected_by_schema(self):
        payload = self.base_payload()
        payload["state"]["integration_connections"][0]["credential_reference"] = "plain-value"
        self.assertEqual(self.codes_for(payload), ["SCHEMA_PATTERN"])

    def test_audit_in_other_workspace(self):
        payload = self.base_payload()
        for event in payload["state"]["audit_events"]:
            if event["target_type"] == "integration_connection":
                event["workspace_uuid"] = payload["state"]["workspaces"][0]["workspace_uuid"]
        self.assertEqual(self.codes_for(payload), ["INV_CROSS_WORKSPACE_REFERENCE"])

    def test_snapshot_rejects_non_mgmt_codes(self):
        payload = self.base_payload()
        payload["entitlement_snapshots"][0]["codes"].append("erp.application")
        self.assertEqual(self.codes_for(payload), ["INV_SNAPSHOT_MISMATCH"])


class CliTests(unittest.TestCase):
    def test_no_command_is_usage_error(self):
        self.assertEqual(run_cli()[0], cc.EXIT_USAGE)

    def test_unknown_option_is_usage_error(self):
        self.assertEqual(run_cli("validate", "--nope")[0], cc.EXIT_USAGE)

    def test_check_missing_file_is_usage_error(self):
        code, _, err = run_cli("check", os.path.join(HERE, "does-not-exist.json"))
        self.assertEqual(code, cc.EXIT_USAGE)
        self.assertIn("USAGE_ERROR", err)

    def test_check_unparseable_file_is_usage_error(self):
        tmp = tempfile.mkdtemp()
        try:
            path = os.path.join(tmp, "broken.json")
            with open(path, "w", encoding="utf-8") as handle:
                handle.write("{not json")
            self.assertEqual(run_cli("check", path)[0], cc.EXIT_USAGE)
        finally:
            shutil.rmtree(tmp)

    def test_check_valid_and_invalid_payload(self):
        fixtures = cc.load_fixtures(cc.FIXTURES)
        tmp = tempfile.mkdtemp()
        try:
            good = os.path.join(tmp, "good.json")
            bad = os.path.join(tmp, "bad.json")
            with open(good, "w", encoding="utf-8") as handle:
                json.dump(fixtures["P01_hybrid_ab"]["payload"], handle)
            with open(bad, "w", encoding="utf-8") as handle:
                json.dump(fixtures["N01_cross_tenant_a_to_b"]["payload"], handle)
            self.assertEqual(run_cli("check", good)[0], cc.EXIT_OK)
            code, out, _ = run_cli("check", bad)
            self.assertEqual(code, cc.EXIT_VIOLATIONS)
            self.assertIn("INV_CROSS_WORKSPACE_REFERENCE", out)
        finally:
            shutil.rmtree(tmp)

    def test_missing_fixture_folder_is_usage_error(self):
        self.assertEqual(run_cli("validate", "--fixtures", os.path.join(HERE, "nope"))[0], cc.EXIT_USAGE)

    def test_broken_fixture_expectation_is_violation(self):
        tmp = tempfile.mkdtemp()
        try:
            shutil.copytree(cc.FIXTURES, os.path.join(tmp, "fx"))
            path = os.path.join(tmp, "fx", "negative", "N01_cross_tenant_a_to_b.json")
            with open(path, encoding="utf-8") as handle:
                doc = json.load(handle)
            doc["expect"]["violations"] = ["INV_RAW_SECRET"]
            with open(path, "w", encoding="utf-8") as handle:
                json.dump(doc, handle)
            code, out, _ = run_cli("validate", "--fixtures", os.path.join(tmp, "fx"))
            self.assertEqual(code, cc.EXIT_VIOLATIONS)
            self.assertIn("FIXTURE_EXPECTATION scope=fixture:N01_cross_tenant_a_to_b", out)
        finally:
            shutil.rmtree(tmp)


class SchemaSubsetTests(unittest.TestCase):
    def test_unsupported_keyword_fails_closed(self):
        schema = cc.Schema({"type": "object", "properties": {"a": {"type": "string", "format": "email"}}})
        self.assertEqual(schema.keyword_errors(), [("#/properties/a", "format")])

    def test_nullable_pattern_applies_only_to_strings(self):
        schema = cc.Schema({"type": ["string", "null"], "pattern": "^x$"})
        self.assertEqual(schema.validate(None), [])
        self.assertEqual(schema.validate("x"), [])
        self.assertEqual([e[0] for e in schema.validate("y")], ["SCHEMA_PATTERN"])

    def test_boolean_is_not_integer(self):
        self.assertEqual([e[0] for e in cc.Schema({"type": "integer"}).validate(True)], ["SCHEMA_TYPE"])

    def test_schema_drift_against_registry_detected(self):
        ctx = cc.build_context(cc.REPO_ROOT)
        ctx["schema"].doc["$defs"]["workspace"]["properties"]["status"]["enum"].append("paused")
        report = cc.Report()
        cc.check_contracts(ctx, report)
        self.assertIn("MODEL_ENUM_DRIFT", report.codes())


class MoodleCrossCheckTests(unittest.TestCase):
    def _fake_repo(self, mutate=None):
        tmp = tempfile.mkdtemp()
        mapping = cc.load_json(cc.MAPPING)
        for rel in mapping["moodle_sources"].values():
            target = os.path.join(tmp, rel)
            os.makedirs(os.path.dirname(target), exist_ok=True)
            shutil.copy(os.path.join(cc.REPO_ROOT, rel), target)
        if mutate:
            mutate(tmp, mapping)
        return tmp

    def test_drift_in_moodle_status_list_detected(self):
        def mutate(root, mapping):
            path = os.path.join(root, mapping["moodle_sources"]["workspace_mapping_service"])
            with open(path, encoding="utf-8") as handle:
                text = handle.read()
            text = text.replace("['pending', 'active', 'degraded', 'suspended', 'closed', 'error']",
                                "['pending', 'active', 'suspended', 'closed', 'error']")
            with open(path, "w", encoding="utf-8") as handle:
                handle.write(text)
        tmp = self._fake_repo(mutate)
        try:
            ctx = cc.build_context(tmp)
            report = cc.Report()
            cc.check_contracts(ctx, report)
            self.assertIn("MAPPING_TARGET_UNKNOWN", report.codes())
        finally:
            shutil.rmtree(tmp)

    def test_new_legacy_tariff_code_needs_mapping(self):
        def mutate(root, mapping):
            path = os.path.join(root, mapping["moodle_sources"]["bms_tariff_catalog_service"])
            with open(path, encoding="utf-8") as handle:
                text = handle.read()
            text = text.replace("'bms.ai_operations']", "'bms.ai_operations', 'bms.new_limit']")
            with open(path, "w", encoding="utf-8") as handle:
                handle.write(text)
        tmp = self._fake_repo(mutate)
        try:
            report = cc.Report()
            cc.check_contracts(cc.build_context(tmp), report)
            self.assertIn("INV_LEGACY_MAPPING_MISSING", report.codes())
        finally:
            shutil.rmtree(tmp)

    def test_unreadable_moodle_source_is_usage_error(self):
        tmp = tempfile.mkdtemp()
        try:
            self.assertEqual(run_cli("--repo-root", tmp, "validate")[0], cc.EXIT_USAGE)
        finally:
            shutil.rmtree(tmp)


class Reg002Tests(unittest.TestCase):
    """verify-reg002 is exercised with a synthetic ZIP and manifest; the real package is not in the repo."""

    def setUp(self):
        self.tmp = tempfile.mkdtemp()
        self.members = {"a.yaml": b"model: synthetic\n", "b.json": b"{\"synthetic\": true}\n", "skip.png": b"\x89PNG"}

    def tearDown(self):
        shutil.rmtree(self.tmp)

    def _zip(self, members):
        path = os.path.join(self.tmp, "pkg.zip")
        with zipfile.ZipFile(path, "w") as archive:
            for name, data in members.items():
                archive.writestr("REG-002/" + name, data)
        return path

    def _manifest(self, zip_path, members):
        with open(zip_path, "rb") as handle:
            blob = handle.read()
        return {"package": {"sha256": hashlib.sha256(blob).hexdigest(), "size_bytes": len(blob)},
                "selected_members": [{"name": n, "sha256": hashlib.sha256(d).hexdigest()} for n, d in members.items()]}

    def test_pass_extracts_only_selected(self):
        path = self._zip(self.members)
        selected = {k: v for k, v in self.members.items() if k != "skip.png"}
        out = os.path.join(self.tmp, "out")
        ok, lines = cc.verify_reg002(path, self._manifest(path, selected), out)
        self.assertTrue(ok, lines)
        self.assertEqual(sorted(os.listdir(out)), ["a.yaml", "b.json"])

    def test_tampered_member_fails_and_does_not_extract(self):
        path = self._zip(self.members)
        manifest = self._manifest(path, {"a.yaml": b"original\n"})
        out = os.path.join(self.tmp, "out")
        ok, lines = cc.verify_reg002(path, manifest, out)
        self.assertFalse(ok)
        self.assertFalse(os.path.exists(out))
        self.assertTrue(any(line.startswith("FAIL member a.yaml") for line in lines))

    def test_wrong_zip_hash_fails(self):
        path = self._zip(self.members)
        manifest = self._manifest(path, {"a.yaml": self.members["a.yaml"]})
        manifest["package"]["sha256"] = "0" * 64
        self.assertFalse(cc.verify_reg002(path, manifest)[0])

    def test_duplicate_or_missing_member_fails(self):
        path = os.path.join(self.tmp, "dup.zip")
        with zipfile.ZipFile(path, "w") as archive:
            archive.writestr("x/a.yaml", b"1")
            archive.writestr("y/a.yaml", b"1")
        manifest = self._manifest(path, {"a.yaml": b"1", "missing.json": b""})
        ok, lines = cc.verify_reg002(path, manifest)
        self.assertFalse(ok)
        self.assertIn("FAIL member a.yaml found 2 times", lines)
        self.assertIn("FAIL member missing.json found 0 times", lines)

    def test_missing_zip_is_usage_error(self):
        code, _, err = run_cli("verify-reg002", "--zip", os.path.join(self.tmp, "absent.zip"))
        self.assertEqual(code, cc.EXIT_USAGE)

    def test_real_manifest_pins_codex_verified_hash(self):
        manifest = cc.load_json(cc.MANIFEST)
        self.assertEqual(manifest["package"]["sha256"], "093fa2936bc9f32c2828f9acfb2459de37fc221701319d1b9a6e91a699da28f3")
        self.assertEqual(len(manifest["selected_members"]), 4)


if __name__ == "__main__":
    unittest.main()
