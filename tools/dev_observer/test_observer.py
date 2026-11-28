import contextlib
from datetime import datetime, timezone
from io import StringIO
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

from github import CollectionError, GitHub, collect, get_response, registry_assignments
from observer import OUTPUTS, main, read_json
from policy import classify

FIXTURE = Path(__file__).with_name("fixture.json")
AT = "2026-11-28T06:00:01+00:00"


class ObserverTests(unittest.TestCase):
    def setUp(self):
        self.bundle = read_json(FIXTURE)
        self.raw = self.bundle["snapshot"]
        self.ev = self.bundle["evidence"]
        self.pr = next(x for x in self.raw["tasks"] if x["number"] == 17)

    def result(self):
        snapshot = classify(self.raw, self.ev, datetime.fromisoformat(AT))
        return {x["number"]: x for x in snapshot["findings"]}

    def test_d01_unassigned_and_unknown_dependencies(self):
        result = self.result()
        self.assertEqual(result[10]["reason"], "assignment_unknown")
        self.assertEqual(result[18]["reason"], "dependencies_unconfirmed")

    def test_d02_hold_propagates_only_to_dependants(self):
        result = self.result()
        self.assertEqual([result[i]["status"] for i in (11, 12, 13)], ["HOLD", "BLOCKED", "READY"])
        self.raw["tasks"][1]["registry_status"] = None
        self.assertEqual(self.result()[12]["status"], "BLOCKED")
        self.ev["tasks"]["11"]["completed"] = True
        self.assertEqual(self.result()[12]["status"], "READY")
        self.ev["tasks"]["11"]["dependencies"] = [12]
        self.assertEqual(self.result()[12]["status"], "BLOCKED")

    def test_d07_skipped_required_job_and_newer_failure(self):
        self.assertEqual(self.result()[17]["ci"], "PASS")
        self.pr["runs"][0]["jobs"][1]["conclusion"] = "skipped"
        self.assertEqual(self.result()[17]["ci"], "FAIL")
        self.pr["runs"][0]["jobs"][1]["conclusion"] = "success"
        new = dict(self.pr["runs"][0], id=112, conclusion="failure")
        self.pr["runs"].append(new)
        self.assertEqual(self.result()[17]["ci"], "FAIL")
        self.pr["runs"].pop()
        del self.ev["tasks"]["17"]["gate"]
        self.assertEqual(self.result()[17]["ci"], "UNKNOWN")

    def test_d08_stale_review_head_base_and_snapshot(self):
        self.pr["reviews"][0]["head"] = "f" * 40
        self.assertEqual(self.result()[17]["independent_review"], "STALE")
        self.pr["head"] = "f" * 40
        self.assertEqual(self.result()[17]["ci"], "STALE")
        self.pr["changed"] = True
        self.assertEqual(self.result()[17]["status"], "STALE")
        self.pr["changed"] = False
        self.pr["base"] = "f" * 40
        self.assertEqual(self.result()[17]["status"], "STALE")

    def test_d17_docs_drift_and_unavailable(self):
        self.assertEqual(self.result()[14]["docs_detail"], "DRIFT")
        self.assertEqual(self.result()[15]["docs_detail"], "NOT RUN")
        self.assertEqual(self.result()[15]["status"], "HOLD")

    def test_d18_offline_no_http_or_process_launch_and_no_overwrite(self):
        with tempfile.TemporaryDirectory() as temp, patch("observer.collect", side_effect=AssertionError), \
                patch("subprocess.Popen", side_effect=AssertionError), patch("socket.socket", side_effect=AssertionError):
            path = Path(temp) / "out"
            args = ["--fixture", str(FIXTURE), "--as-of", AT, "--out", str(path)]
            self.assertEqual(main(args), 0)
            self.assertEqual({p.name for p in path.iterdir()}, set(OUTPUTS))
            with contextlib.redirect_stderr(StringIO()):
                self.assertEqual(main(args), 2)

    def test_untrusted_fields_do_not_reach_any_reports(self):
        marker = "ghp_test_secret_with_instructions"
        self.pr["title"] = self.pr["body"] = marker
        self.pr["executor"] = marker
        self.pr["runs"][0]["jobs"][1]["name"] = marker
        self.pr["reviews"][0]["body"] = marker
        self.raw["errors"] = [{"endpoint": marker, "reason": marker}]
        result = classify(self.raw, self.ev, datetime.fromisoformat(AT))
        self.assertNotIn(marker, json.dumps(result))
        self.assertFalse(result["complete"])
        self.assertEqual(self.result()[17]["status"], "UNKNOWN")

    def test_self_review_and_unverified_text(self):
        review = self.ev["tasks"]["17"]["review"]
        review["reviewer"] = "Claude"
        self.assertEqual(self.result()[17]["independent_review"], "UNKNOWN")
        review["reviewer"] = "Codex"
        review["review"] = 999
        self.assertEqual(self.result()[17]["independent_review"], "UNKNOWN")

    def test_package_receipt_and_smoke(self):
        self.assertEqual(self.result()[17]["release"], "VERIFIED")
        release = self.ev["tasks"]["17"]["release"]
        release["receipt"]["smoke"] = "FAIL"
        self.assertEqual(self.result()[17]["release"], "PUBLISHED")
        release["receipt"]["server_confirmed"] = False
        self.assertEqual(self.result()[17]["release_reason"], "server_receipt_missing")
        self.pr["runs"][1]["artifacts"] = []
        self.assertEqual(self.result()[17]["release_reason"], "artifact_missing")
        release["package"]["manifest"]["zip_sha256"] = "0" * 64
        self.assertEqual(self.result()[17]["release_reason"], "zip_manifest_baseline_mismatch")

    def test_missing_baseline_and_unknown_state(self):
        self.pr["state"] = "unrecognized"
        self.assertEqual(self.result()[17]["status"], "UNKNOWN")
        self.pr["state"] = "open"
        self.pr["tree"] = None
        self.assertEqual(self.result()[17]["status"], "UNKNOWN")

    def test_stale_exit_and_invalid_exit(self):
        with tempfile.TemporaryDirectory() as temp, contextlib.redirect_stderr(StringIO()):
            base = Path(temp)
            self.assertEqual(main(["--fixture", str(FIXTURE), "--as-of", "2026-11-30T00:00:00Z",
                                   "--out", str(base / "stale")]), 1)
            self.assertEqual(main(["--fixture", str(base / "absent"), "--out", str(base / "absent-out")]), 2)


class GitHubTests(unittest.TestCase):
    def test_get_is_the_only_transport_and_redirects_are_rejected(self):
        with patch("github.HTTPSConnection") as connection:
            conn = connection.return_value
            conn.getresponse.return_value.status = 302
            conn.getresponse.return_value.getheaders.return_value = [("Location", "https://example.org")]
            conn.getresponse.return_value.read.return_value = b""
            api = GitHub("test", request=get_response)
            with self.assertRaisesRegex(CollectionError, "api_error"):
                api.get("/issues/198")
            conn.request.assert_called_once()
            self.assertEqual(conn.request.call_args.args[0], "GET")
            self.assertEqual(connection.call_args.args[0], "api.github.com")
            for endpoint in ("/dispatches", "/actions/artifacts/1/zip", "//example.com", "/issues/1/../../"):
                with self.assertRaisesRegex(CollectionError, "endpoint_not_allowed"):
                    api.get(endpoint)
            self.assertEqual(conn.request.call_count, 1)

    def test_pagination_does_not_follow_link_urls(self):
        calls = []

        def request(path, _):
            calls.append(path)
            return (200, {"link": '<https://attacker.invalid>; rel="next"'}, b'[{"id":1}]') if len(calls) == 1 \
                else (200, {}, b'[{"id":2}]')

        self.assertEqual([r["id"] for r in GitHub("test", request=request).list("/pulls")], [1, 2])
        self.assertTrue(calls[1].endswith("page=2"))
        self.assertNotIn("attacker", "".join(calls))

    def test_bounded_rate_limit_and_partial_snapshot(self):
        delays = []
        api = GitHub("test", request=lambda *_: (429, {"retry-after": "1"}, b"private"), sleep=delays.append)
        with self.assertRaisesRegex(CollectionError, "rate_limited"):
            api.get("/issues/198")
        self.assertEqual(delays, [1, 1])
        partial = collect(GitHub("test", request=lambda *_: (403, {}, b"private")))
        self.assertFalse(partial["complete"])
        self.assertIn({"endpoint": "/issues/198", "reason": "api_denied"}, partial["errors"])
        api = GitHub("test", request=lambda *_: (200, {}, b'{"total_count":2,"jobs":[{"id":1}]}'))
        with self.assertRaisesRegex(CollectionError, "incomplete_page"):
            api.list("/actions/runs/1/attempts/1/jobs", "jobs")

    def test_registry_hold_and_unrelated_hold_text(self):
        registry = """## Активные линии
| Release Operator | #129–#143 | Codex | Codex | Владелец 28.11: HOLD. |
| BDO | #202 (Issue) | Codex | Codex | READY; Release Operator HOLD. |
## Без исполнителя
| Wrong | #143 | Claude | - | - |
"""
        expected = dict.fromkeys(range(129, 144), ("Codex", "HOLD"))
        expected[202] = ("Codex", None)
        self.assertEqual(registry_assignments(registry), expected)

if __name__ == "__main__":
    unittest.main()
