# BMS Release Operator V1f — restricted host read-only bridge

Status: **CODE READY FOR REVIEW; NOT INSTALLED.** Dependent on PR #137 -> #133 -> #132 -> #131 -> #130 -> #129. Only two permitted SSH commands: `inventory` and `capacity`. No deploy/backup/restore/upgrade capability.

## What this adds

- `tools/release_operator/forced_readonly_v1f.sh`: a proposed restricted SSH forced-command. The `inventory` path preserves the earlier accepted `tools/ruweb_inventory_forced_command.sh` behavior for `edu.kzn.mba`. The new `capacity` path runs a fixed host-side Python script, ignoring SSH parameters. Any other command, including shell, backup, deploy, arbitrary file paths and compound commands, exits 2.
- `tools/release_operator/capacity_bridge.py`: reads a fixed local, protected JSON capacity policy. It refuses group/other writable directories, symlink components, untrusted file ownership, invalid/duplicate JSON fields, placeholders, wrong site root, zero reservations. It then calls V1e's read-only shared-volume accounting. **Never produces a GO**. It reports provider quota and host authenticity as unverified.
- `test_capacity_bridge.py`: tests denied commands, tampering, forged GO and unsafe policy.

## One-time host administrator setup — NOT DONE

The host administrator should independently review the scripts and copy **reviewed immutable bytes** into the existing restricted private directory:

`/var/www/p706494/data/.bms24-inventory/`

Expected installed names: `capacity_bridge.py`, `storage_preflight.py`, `forced_readonly_v1f.sh`. The already installed `inventory.php` stays unchanged.

The host administrator must create **outside Git and the chat** a `capacity-policy.json` in the same private directory. JSON schema:

```json
{
  "schema": "bms24.release_operator.capacity_policy.v1f",
  "target": "edu.kzn.mba",
  "paths": {
    "site": "/var/www/p706494/data/www/edu.kzn.mba",
    "backup": "/REPLACE_WITH_VERIFIED_BACKUP_DIRECTORY",
    "test": "/REPLACE_WITH_VERIFIED_ISOLATED_TEST_DIRECTORY"
  },
  "reservations": {
    "site": {"bytes": 1, "inodes": 1},
    "backup": {"bytes": 1, "inodes": 1},
    "test": {"bytes": 1, "inodes": 1}
  }
}
```

**This is a schema example, NOT a usable policy.** Replace BOTH placeholder directories and ALL illustrative reserves with measured requirements for each storage volume. Use a private owned file not writable by group/others and place it in a directory not writable by untrusted accounts. The `capacity` command refuses placeholders but it cannot know whether a nonzero budget is sufficient.

For the dedicated SSH public key, an authorized administrator may change the existing forced-command path only after proving both commands work; set SSH authorized_keys restrictions `restrict,command="/ABSOLUTE/REVIEWED/forced_readonly_v1f.sh"` (no PTY/agent/tunnel/port forwarding). Never paste the SSH private key into Git or chat. The SSH account must have **no other server-side deployment privileges**. Confirm that the exact configured forced command cannot be bypassed.

From the operator's trusted machine, `ssh -T <restricted-alias> inventory` and `ssh -T <restricted-alias> capacity` should return metadata/HOLD; all other commands must print DENIED. These are instructions, not remotely executed evidence. Quota must be separately checked in provider panel/API by the authorized operator.

## Trust boundary

The forced command can collect useful remote read-only evidence after installation, but it does NOT authenticate receipts cryptographically, enforce a server-wide operator lock, pause writers, run fresh backup/restore or perform migration. Its `status=HOLD` and `deployable=false` are permanent. Never treat passing CI as evidence that the wrapper was installed on the server.

Existing `prepare-release.yml` remains HOLD. No PR merges, production changes or new SSH keys were performed as part of V1f. Daily reports #124 remain first in the release order; #135 is separate.

## Next step after host installation

1. Through a verified restricted SSH key, record both production `inventory` and `capacity` with fresh timestamps and host/identity confirmation; independently verify provider quota.
2. On a disposable isolated site, test a *different* privileged migration executor with continuous web/API/cron pause, full consistent DB+code+moodledata+config capture, verified independent restore and SIGKILL/partial-DDL recovery.
3. Obtain explicit execution window and separate decision on `dailyreports_enabled`; use only exact #124 ZIP and current production baseline.
4. Execute and verify real production installation only after the live migration operator tests PASS. **No code-only rollback after DDL.**
