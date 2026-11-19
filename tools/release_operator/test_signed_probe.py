"""V1d: exercise the real PHP signer and OpenSSL verifier with EPHEMERAL test RSA keys.

No production keys, network or Moodle instance are involved.
"""
from __future__ import annotations

import base64
from datetime import datetime, timezone, timedelta
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parent))
from verify_signed_probe import AttestationHold, verify

ROOT = Path(__file__).resolve().parent
SIGNER = ROOT / "sign_probe.php"
RELEASE = "BMS24-R1R2-20261119"
NONCE = "ab" * 32


class SignedProbeTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        if not shutil.which("openssl") or not shutil.which("php"):
            raise unittest.SkipTest("PHP CLI and OpenSSL CLI required")
        cls.tmp = tempfile.TemporaryDirectory()
        cls.dir = Path(cls.tmp.name)
        cls.private = cls.dir / "ephemeral-test-key.pem"
        cls.public = cls.dir / "ephemeral-test-pub.pem"
        p = subprocess.run(
            ["openssl", "genpkey", "-algorithm", "RSA",
             "-pkeyopt", "rsa_keygen_bits:2048", "-out", str(cls.private)],
            capture_output=True, text=True, timeout=20
        )
        if p.returncode:
            raise RuntimeError("ephemeral test key generation failed")
        cls.private.chmod(0o600)
        p = subprocess.run(
            ["openssl", "pkey", "-in", str(cls.private), "-pubout",
             "-out", str(cls.public)], capture_output=True, text=True, timeout=10
        )
        if p.returncode:
            raise RuntimeError("ephemeral test public key extraction failed")
        cls.keyhash = hashlib.sha256(cls.public.read_bytes()).hexdigest()

    @classmethod
    def tearDownClass(cls):
        if hasattr(cls, "tmp"):
            cls.tmp.cleanup()

    def setUp(self):
        self.work = tempfile.TemporaryDirectory(dir=self.dir)
        self.addCleanup(self.work.cleanup)
        self.place = Path(self.work.name)
        self.challenge = self.place / "challenge.json"
        self.probe = self.place / "probe.json"
        self.challenge_data = {
            "schema": "bms24.release_operator.challenge.v1d",
            "release_id": RELEASE, "target": "production", "nonce": NONCE
        }
        self.probe_data = {
            "schema": "bms24.release_operator.server_probe.v1c",
            "status": "HOLD", "deployable": False,
            "origin_authenticated": False, "target_claimed": "UNVERIFIED_LOCAL_SITE",
            "captured_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
            "software": {"moodle_version": "4.1.22"},
            "actions_executed": [],
        }
        self.save()

    def save(self):
        self.challenge.write_text(json.dumps(self.challenge_data), encoding="utf-8")
        self.probe.write_text(json.dumps(self.probe_data), encoding="utf-8")

    def sign(self, *, optin=True, key=None):
        env = dict(os.environ)
        env.pop("BMS_RELEASE_SIGNING_OPTIN", None)
        if optin:
            env["BMS_RELEASE_SIGNING_OPTIN"] = "1"
        env["BMS_RELEASE_PRIVATE_KEY_FILE"] = str(key or self.private)
        return subprocess.run(
            ["php", str(SIGNER), str(self.challenge), str(self.probe), str(self.public)],
            text=True, capture_output=True, env=env, timeout=12
        )

    def envelope(self):
        process = self.sign()
        self.assertEqual(process.returncode, 0, process.stdout + process.stderr)
        return json.loads(process.stdout)

    def check(self, env, **kw):
        return verify(env, public_key=kw.get("public_key", self.public),
                      expected_key_sha256=kw.get("keyhash", self.keyhash),
                      release_id=kw.get("release", RELEASE),
                      target=kw.get("target", "production"),
                      nonce=kw.get("nonce", NONCE),
                      now=kw.get("now", datetime.now(timezone.utc)))

    def test_actual_php_rsa_signature_verified_by_python_stays_hold(self):
        env = self.envelope()
        result = self.check(env)
        self.assertEqual(result["status"], "HOLD")
        self.assertFalse(result["deployable"])
        self.assertTrue(result["signature_verified"])
        self.assertTrue(result["challenge_nonce_verified"])
        self.assertFalse(result["server_origin_authenticated"])
        self.assertFalse(result["inventory_claims_verified_against_live_host"])
        self.assertEqual(result["actions_executed"], [])

    def test_changed_report_bytes_are_rejected(self):
        env = self.envelope()
        raw = base64.b64decode(env["payload_b64"])
        env["payload_b64"] = base64.b64encode(raw + b" ").decode()
        with self.assertRaises(AttestationHold):
            self.check(env)

    def test_changed_signature_is_rejected(self):
        env = self.envelope()
        signature = bytearray(base64.b64decode(env["signature_b64"]))
        signature[0] ^= 1
        env["signature_b64"] = base64.b64encode(signature).decode()
        with self.assertRaises(AttestationHold):
            self.check(env)

    def test_wrong_nonce_and_target_block_replays(self):
        env = self.envelope()
        with self.assertRaises(AttestationHold):
            self.check(env, nonce="ef" * 32)
        with self.assertRaises(AttestationHold):
            self.check(env, target="staging")
        env["nonce"] = "ef" * 32
        with self.assertRaises(AttestationHold):
            self.check(env, nonce="ef" * 32)

    def test_untrusted_signing_key_or_modified_public_key_rejected(self):
        env = self.envelope()
        with self.assertRaises(AttestationHold):
            self.check(env, keyhash="00" * 32)
        env["key_id"] = "f" * 64
        with self.assertRaises(AttestationHold):
            self.check(env)

    def test_stale_signed_inventory_rejected(self):
        env = self.envelope()
        with self.assertRaises(AttestationHold):
            self.check(env, now=datetime.now(timezone.utc) + timedelta(minutes=6))

    def test_extra_fields_claiming_deploy_permission_are_rejected(self):
        env = self.envelope()
        env["deployable"] = True
        with self.assertRaises(AttestationHold):
            self.check(env)

    def test_signer_rejects_missing_optin_and_private_key_permissions(self):
        proc = self.sign(optin=False)
        self.assertEqual(proc.returncode, 2)
        self.assertEqual(json.loads(proc.stdout)["status"], "HOLD")
        loose = self.place / "loose-private.pem"
        loose.write_bytes(self.private.read_bytes())
        loose.chmod(0o644)
        proc = self.sign(key=loose)
        self.assertEqual(proc.returncode, 2)
        self.assertNotIn("PRIVATE", proc.stdout)
        self.assertEqual(json.loads(proc.stdout)["status"], "HOLD")

    def test_signer_rejects_forged_claims_and_stale_probe(self):
        self.probe_data["origin_authenticated"] = True
        self.save()
        self.assertEqual(self.sign().returncode, 2)
        self.probe_data["origin_authenticated"] = False
        self.probe_data["captured_at"] = (
            datetime.now(timezone.utc) - timedelta(minutes=7)
        ).strftime("%Y-%m-%dT%H:%M:%SZ")
        self.save()
        self.assertEqual(self.sign().returncode, 2)

    def test_signer_rejects_invalid_challenge_nonce(self):
        self.challenge_data["nonce"] = "../../fake"
        self.save()
        self.assertEqual(self.sign().returncode, 2)

    def test_php_lint(self):
        p = subprocess.run(["php", "-l", str(SIGNER)],
                           capture_output=True, text=True, timeout=10)
        self.assertEqual(p.returncode, 0, p.stdout + p.stderr)


if __name__ == "__main__":
    unittest.main()
