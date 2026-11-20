"""V1f SSH bridge tests; no real server connection."""
from __future__ import annotations
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
sys.path.insert(0, str(Path(__file__).resolve().parent))
from capacity_bridge import CapacityHold, SCHEMA, audit, safe_policy_file, validate_policy
ROOT = Path(__file__).resolve().parent
SHELL = ROOT / "forced_readonly_v1f.sh"
SITE = "/var/www/p706494/data/www/edu.kzn.mba"

def valid():
    return {"schema":SCHEMA, "target":"edu.kzn.mba",
            "paths":{"site":SITE, "backup":"/srv/backup/bms24", "test":"/srv/test/bms24"},
            "reservations":{name:{"bytes":1000, "inodes":100} for name in ("site","backup","test")}}

class CapacityBridgeTest(unittest.TestCase):
    def test_success_is_hold_not_go(self):
        def meter(paths, reserves):
            return {"status":"HOLD","deployable":False,"declared_reserve_met":True,"actions_executed":[]}
        r=audit(json.dumps(valid()).encode(), meter=meter)
        self.assertEqual(r["status"],"HOLD")
        self.assertFalse(r["deployable"])
        self.assertFalse(r["provider_quota_verified"])
        self.assertFalse(r["server_identity_authenticated"])
        self.assertEqual(r["actions_executed"],[])

    def test_target_and_extra_key_rejected(self):
        for field, bad in (("target","other"),("schema","other")):
            p=valid()
            p[field]=bad
            with self.subTest(field=field), self.assertRaises(CapacityHold):
                validate_policy(json.dumps(p).encode())
        p=valid()
        p["allow_deploy"]=True
        with self.assertRaises(CapacityHold):
            validate_policy(json.dumps(p).encode())

    def test_wrong_site_and_placeholder_rejected(self):
        for key,bad in (("site","/srv/fake"),("backup","/PRIVATE/backup"),("test","relative")):
            p=valid()
            p["paths"][key]=bad
            with self.subTest(key=key), self.assertRaises(CapacityHold):
                validate_policy(json.dumps(p).encode())

    def test_zero_negative_bool_string_reserves_rejected(self):
        for bad in (0,-1,True,"1024",None):
            p=valid()
            p["reservations"]["backup"]["bytes"]=bad
            with self.subTest(bad=bad), self.assertRaises(CapacityHold):
                validate_policy(json.dumps(p).encode())

    def test_duplicate_json_keys_rejected(self):
        with self.assertRaises(CapacityHold):
            validate_policy(b'{"schema":"a","schema":"b"}')

    def test_meter_cannot_grant_deploy(self):
        with self.assertRaises(CapacityHold):
            audit(json.dumps(valid()).encode(),meter=lambda a,b:{"status":"PASS","deployable":True,"actions_executed":["deploy"]})

    def test_forced_command_rejects_shell_deploy_and_arguments(self):
        for original in ("","deploy","capacity --test /tmp","inventory; id",
                         "sh","backup","restore","capacity\ninventory","bash -c id"):
            env=dict(os.environ)
            env["SSH_ORIGINAL_COMMAND"]=original
            r=subprocess.run(["/bin/sh",str(SHELL)],env=env,capture_output=True,text=True,timeout=5)
            with self.subTest(original=original):
                self.assertEqual(r.returncode,2)
                self.assertIn("DENIED",r.stderr)
                self.assertEqual(r.stdout,"")

    def test_shell_has_exact_allowlist_no_user_exec(self):
        s=SHELL.read_text()
        self.assertIn('case "${SSH_ORIGINAL_COMMAND:-}"',s)
        self.assertIn("  inventory)",s)
        self.assertIn("  capacity)",s)
        self.assertIn("/usr/bin/python3 -E -s",s)
        for bad in ("eval ","bash -c","sudo ","ssh "):
            self.assertNotIn(bad,s)

    def test_bridge_uses_fixed_policy_and_refuses_cli_args(self):
        code=(ROOT/"capacity_bridge.py").read_text()
        self.assertIn("if len(sys.argv) != 1:",code)
        self.assertIn("POLICY_PATH = Path(",code)
        self.assertNotIn("argparse",code)
        self.assertNotIn("subprocess",code)

    def test_world_writable_policy_parent_rejected(self):
        with tempfile.TemporaryDirectory() as tmp:
            p=Path(tmp)/"policy.json"
            p.write_text(json.dumps(valid()))
            with self.assertRaises(CapacityHold):
                safe_policy_file(p)

if __name__=="__main__":
    unittest.main()
