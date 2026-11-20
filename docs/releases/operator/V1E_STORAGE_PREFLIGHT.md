# BMS Release Operator V1e — read-only storage preflight

STATUS: HOLD. Depends on V1d draft PR #133, which depends on #132 -> #131 -> #130 -> #129. This is not a production installer. No server or SSH changes.

## Rationale

Prior operator inventory reported 4767711232 available bytes against a 4731174912-byte provisional reserve. That small difference does not prove capacity, because provider quotas may be separate from filesystem space. Production, backup and staging directories may share one underlying filesystem, causing the same bytes and inodes to be counted repeatedly.

The Python script tools/release_operator/storage_preflight.py checks existing local site, backup and test directories and groups them by filesystem device ID. It sums reserved bytes and reserved inodes within each common filesystem instead of summing free capacity across paths. A shared filesystem with 1000 free bytes and three allocations of 400 bytes must fail capacity (1200 > 1000). The script rejects symlinks, missing directories, malformed reservations and changing measurements while traversing one device.

Even with enough local disk, the result always remains HOLD / deployable=false / actions_executed=[]. Provider-level account quota, external mounts, thin provisioning, free space growth, authentication of the server and real backup recovery remain UNVERIFIED. Different device IDs do not guarantee independent provider quotas.

## Example command, only for a trusted authorized local host

    python3 tools/release_operator/storage_preflight.py --site /PRIVATE/PRODUCTION_VOLUME --backup /PRIVATE/BACKUP_VOLUME --test /PRIVATE/ISOLATED_TEST_VOLUME --site-bytes 500000000 --backup-bytes 3000000000 --test-bytes 4731174912 --site-inodes 10000 --backup-inodes 20000 --test-inodes 20000

All paths and reservations above are ILLUSTRATIVE placeholders, not verified server paths or sufficient release budgets. A real operator must recalculate SQL, code, moodledata, snapshot, scratch, restore, account quota, inode and growth requirements before backup.

Run negative tests:

    python3 -m unittest discover -s tools/release_operator -p 'test_*.py' -v

## Remaining production conditions

1. Authenticated live host inventory and hosting account quota.
2. Server-wide lock and continuous stop of web/API/cron writers.
3. Fresh full consistent backup, independent isolated restore of DB+code+config+moodledata.
4. Crash/partial-DDL tests with phase-aware recovery; no automatic code-only downgrade to R0 once DDL starts.
5. Exact immutable ZIP/manifest/CI and separate feature-flag authorization.
6. Privileged server executor and receipt, not present in this ChatGPT session.

The historical prepare-release.yml remains intentionally HOLD. No production/staging/dev installation has been made by this PR. #124 and #135 remain NOT PUBLISHED.