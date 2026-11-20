# BMS Release Operator V1g — pinned SSH inventory client

Status: REVIEW-ONLY, NOT INSTALLED / NOT CONNECTED. Depends on V1f PR #138 -> #137 -> #133 -> #132 -> #131 -> #130 -> #129. All code remains draft. Does not deploy, backup, restore, restart cron, change web/API writes or migrate Moodle.

## Purpose

The existing V1f SSH forced-command allows exactly two read-only remote verbs: inventory and capacity. V1g adds a controller-side client that pins the SSH endpoint key using a protected known_hosts file and SHA256 digest supplied from a protected policy outside Git/chat.

File: tools/release_operator/readonly_ssh_client.py.

The controller refuses unexpected remote commands, missing or improperly protected policy/SSH key/known_hosts material, ambiguous or unexpected JSON, stale receipts, an unexpected Moodle inventory type, fake capacity GO status or a fake server/quota verification claim. It never accepts an SSH remote shell or options from user input. It uses SSH BatchMode, StrictHostKeyChecking=yes, UpdateHostKeys=no, IdentitiesOnly=yes, IdentityAgent=none, ClearAllForwardings=yes and no host key DNS fallback. It passes only the literal verb inventory or capacity.

The returned output is a reduced non-sensitive summary, not the raw inventory database/table metadata. Each successful transport/shape check still yields status HOLD and deployable=false. An authenticated SSH endpoint key does not prove the correctness of server data, the physical production host, account quota, a tested backup, or permission to deploy. A malicious signer/SSH account may still lie about measurements.

## Protected local operator setup — NOT PERFORMED

An authorized administrator must first review and deploy the V1f forced-command on the correct server, with a dedicated restricted SSH account, a pinned host key verified via an independent trusted channel and a host-owned capacity policy. Do not send SSH keys, DB credentials, host secrets or sensitive reports to ChatGPT or GitHub.

Place a private controller policy at /etc/bms24-release-operator/readonly-client.json (owned by root or the restricted operator, not group/other writable). Example schema only:

    {
      "schema": "bms24.release_operator.ssh_reader_policy.v1g",
      "target": "edu.kzn.mba",
      "host": "REPLACE_WITH_ACTUAL_SSH_HOSTNAME",
      "port": 22,
      "user": "bmsinventory",
      "identity_file": "/etc/bms24-release-operator/keys/readonly",
      "known_hosts_file": "/etc/bms24-release-operator/known_hosts",
      "known_hosts_sha256": "REPLACE_WITH_TRUSTED_SHA256_OF_KNOWN_HOSTS"
    }

The hostname and key digest are *placeholders*. The client must never obtain trust material through the untrusted SSH connection it is checking. Known_hosts and identity files must be protected and reachable by the operator. The identity file must be mode 0600. The policy's root path and SSH host identity must be independently established. Do not disable host key checking.

After independent setup, from a trusted operator machine:

    python3 tools/release_operator/readonly_ssh_client.py inventory
    python3 tools/release_operator/readonly_ssh_client.py capacity

These are future intended commands; they have NOT been executed against a real server. Even a successful run outputs HOLD. There is no background job, scheduled action or deployment trigger.

## CI tests

    python3 -m unittest discover -s tools/release_operator -p 'test_*.py' -v

Synthetic tests inject a fake subprocess runner (never a real SSH connection), check exact flags and allowlisted verbs, host-key pin handling, error/timeout conditions, stale inventory and capacity, duplicate/forged fields, and guarantee that business metadata is not reprinted in the summary.

## Next real operator actions

1. Authorized host administrator installs and audits the V1f server-side forced command, pinned SSH host identity and read-only capacity-policy; confirms inventory/capacity work and every other command is denied.
2. Run V1g from the trusted controller only after protected local policy exists and verify server's actual R0 baseline, underlying volumes, actual account quota, inode growth and conflicting release locks. This is read-only observation, not a deployment approval.
3. Separate migration operator must pass disposable **actual** Moodle 4.1.22 + Percona backup, independent restore, continuous writer pause and partial-DDL fault injection before production R1/R2 installation.
4. Keep unchanged candidate #124 first, then new Communication Center #135 after its own dev/owner acceptance. Do not mix package identities or automatically downgrade R0 code after DDL.

Existing prepare-release.yml remains HOLD; #124/#135 are not published by V1g.
