#!/usr/bin/env python3
"""V1d: verify a detached RSA-SHA256 attestation of a V1c read-only site probe.

The public key fingerprint, expected target/release/nonce and trusted clock MUST
come from an independent protected control plane, not from the envelope/PR.
A valid signature only proves possession of a signing key. It does NOT prove
the physical host, correctness of server observations or deployment readiness.
No server/DB/GitHub writes, no deploy, no credentials.
"""
from __future__ import annotations

import argparse
import base64
import binascii
from datetime import datetime, timezone, timedelta
import hashlib
import json
from pathlib import Path
import re
import subprocess
import tempfile

from artifact_gate import ArtifactHold, read_json

SCHEMA = "bms24.release_operator.signed_probe.v1d"
PROBE = "bms24.release_operator.server_probe.v1c"
DOMAIN = b"BMS24-READONLY-INVENTORY-V1D\x00"
HEX64 = re.compile(r"[a-f0-9]{64}\Z")
RELEASE = re.compile(r"BMS24-[A-Z0-9][A-Z0-9-]{2,48}\Z")
TARGETS = {"production", "staging", "dev", "synthetic"}
FIELDS = {"schema", "algorithm", "key_id", "release_id", "target",
          "nonce", "payload_b64", "signature_b64"}
MAX_PAYLOAD = 1024 * 1024
MAX_SIGNATURE = 8192


class AttestationHold(ValueError):
    pass


def must(ok: bool, reason: str) -> None:
    if not ok:
        raise AttestationHold(reason)


def message(release_id: str, target: str, nonce: str, payload: bytes) -> bytes:
    """Domain-separated unambiguous ASCII framing; PHP uses identical bytes."""
    must(isinstance(release_id, str) and RELEASE.fullmatch(release_id) is not None, "invalid release")
    must(target in TARGETS, "invalid target")
    must(isinstance(nonce, str) and HEX64.fullmatch(nonce) is not None, "invalid challenge nonce")
    must(isinstance(payload, bytes) and 0 < len(payload) <= MAX_PAYLOAD, "invalid report size")
    parts = (release_id.encode("ascii"), target.encode("ascii"), nonce.encode("ascii"),
             str(len(payload)).encode("ascii"), hashlib.sha256(payload).hexdigest().encode("ascii"))
    return DOMAIN + b"\x00".join(parts) + b"\x00"


def _strict_json(payload: bytes):
    def unique(pairs):
        result = {}
        for k, v in pairs:
            if k in result:
                raise AttestationHold("duplicate probe JSON key")
            result[k] = v
        return result
    try:
        result = json.loads(payload.decode("utf-8"), object_pairs_hook=unique)
    except (ValueError, UnicodeError) as exc:
        raise AttestationHold("unreadable signed probe payload") from exc
    must(isinstance(result, dict), "probe payload must be an object")
    return result


def _stamp(value: object) -> datetime:
    must(isinstance(value, str) and re.fullmatch(r"\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ", value) is not None,
         "invalid inventory captured_at")
    try:
        return datetime.fromisoformat(value[:-1] + "+00:00")
    except ValueError as exc:
        raise AttestationHold("invalid UTC time") from exc


def verify(envelope: dict, *, public_key: Path, expected_key_sha256: str,
           release_id: str, target: str, nonce: str,
           now: datetime) -> dict:
    must(isinstance(envelope, dict) and set(envelope) == FIELDS, "unknown attestation fields")
    must(envelope["schema"] == SCHEMA and envelope["algorithm"] == "RSA-SHA256",
         "unexpected signing scheme")
    must(release_id == envelope["release_id"] and target == envelope["target"] and nonce == envelope["nonce"],
         "attestation challenge mismatch")
    must(isinstance(expected_key_sha256, str) and HEX64.fullmatch(expected_key_sha256) is not None,
         "untrusted public-key fingerprint")
    public_key = Path(public_key)
    must(public_key.is_file() and not public_key.is_symlink() and public_key.stat().st_size <= 16384,
         "public key unavailable or unsafe")
    keydata = public_key.read_bytes()
    pinned_key = hashlib.sha256(keydata).hexdigest()
    must(pinned_key == expected_key_sha256 and envelope["key_id"] == expected_key_sha256,
         "signing key is not independently pinned")
    try:
        payload = base64.b64decode(envelope["payload_b64"], validate=True)
        signature = base64.b64decode(envelope["signature_b64"], validate=True)
    except (ValueError, binascii.Error, TypeError) as exc:
        raise AttestationHold("invalid base64 in signed inventory") from exc
    must(0 < len(signature) <= MAX_SIGNATURE, "invalid signature size")
    signed_message = message(release_id, target, nonce, payload)
    with tempfile.TemporaryDirectory(prefix="bms24-v1d-verify-") as tmp:
        signature_file = Path(tmp) / "signature.bin"
        signature_file.write_bytes(signature)
        try:
            verification = subprocess.run(
                ["openssl", "dgst", "-sha256", "-verify", str(public_key),
                 "-signature", str(signature_file)],
                input=signed_message, capture_output=True, timeout=12, check=False
            )
        except (OSError, subprocess.TimeoutExpired) as exc:
            raise AttestationHold("signature verifier unavailable") from exc
        must(verification.returncode == 0 and
             verification.stdout.strip() == b"Verified OK",
             "detached server signature did not verify")
    probe = _strict_json(payload)
    must(probe.get("schema") == PROBE and probe.get("status") == "HOLD" and
         probe.get("deployable") is False and
         probe.get("origin_authenticated") is False and
         probe.get("target_claimed") == "UNVERIFIED_LOCAL_SITE" and
         probe.get("actions_executed") == [],
         "signed probe contains unsupported claims or non-read-only actions")
    must(now.tzinfo is not None, "trusted UTC clock required")
    age = now.astimezone(timezone.utc) - _stamp(probe.get("captured_at"))
    must(timedelta(seconds=-30) <= age <= timedelta(minutes=5),
         "signed inventory timestamp stale/future")
    return {
        "schema": "bms24.release_operator.attestation_report.v1d",
        "status": "HOLD", "deployable": False,
        "signature_verified": True,
        "key_fingerprint_verified": True,
        "challenge_nonce_verified": True,
        "payload_sha256": hashlib.sha256(payload).hexdigest(),
        "captured_at": probe["captured_at"],
        "server_origin_authenticated": False,
        "inventory_claims_verified_against_live_host": False,
        "actions_executed": [],
        "blockers": [
            "A valid key signature does not independently prove host origin or correctness of measurements",
            "No protected host-to-runner channel, live runtime inventory or approved deployment credential",
            "No completed real backup/restore, continuous writer pause and phase-aware DDL recovery",
            "No owner approval bound to exact ZIP, target and execution window"
        ]
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--envelope", required=True, type=Path)
    parser.add_argument("--public-key", required=True, type=Path)
    parser.add_argument("--expected-key-sha256", required=True)
    parser.add_argument("--release-id", required=True)
    parser.add_argument("--target", required=True)
    parser.add_argument("--nonce", required=True)
    parser.add_argument("--report", required=True, type=Path)
    args = parser.parse_args()
    try:
        outcome = verify(read_json(args.envelope, 2 * MAX_PAYLOAD + 20000),
                         public_key=args.public_key,
                         expected_key_sha256=args.expected_key_sha256,
                         release_id=args.release_id, target=args.target,
                         nonce=args.nonce, now=datetime.now(timezone.utc))
        exitcode = 0
    except (ArtifactHold, AttestationHold, OSError, KeyError, TypeError) as exc:
        outcome = {"schema": "bms24.release_operator.attestation_report.v1d",
                   "status": "REJECTED", "deployable": False,
                   "reason": str(exc)[:180], "actions_executed": []}
        exitcode = 2
    args.report.parent.mkdir(parents=True, exist_ok=True)
    args.report.write_text(json.dumps(outcome, indent=2) + "\n", encoding="utf-8")
    print("BMS24 inventory signature gate: " + outcome["status"] + " (NO DEPLOYMENT)")
    return exitcode


if __name__ == "__main__":
    raise SystemExit(main())
