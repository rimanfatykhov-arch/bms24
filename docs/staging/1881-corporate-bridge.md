# Stage 1881.16: corporate compatibility bridge (staging only)

Canonical tables: local_kbacompany_company / local_kbacompany_member.
Legacy corporate tables: local_kbacorp_company / local_kbacorp_member.

This is a one-time compatibility bridge, not a permanent sync mechanism.
Never run on production. Back up the staging database and code first.

## Prerequisites

- Install the new PHP script at local/kbacompany/cli/corporate_bridge_staging.php.
- Staging Docker is up, using the test database.
- Review company UUID and backup availability.

## Read-only dry run

From the staging VDS shell:

    source /srv/bms24-staging/moodle-test/staging.env
    cd /srv/bms24-staging/moodle-test/docker
    ./bin/moodle-docker-compose exec -T webserver php local/kbacompany/cli/corporate_bridge_staging.php --companyuuid=b1d324df-b6fe-461a-8d6a-c38ec9dc9c6d

Expected: DRY_RUN_OK. If the corporate registry is empty, mapping is MISSING.
If mapping already exists, script checks role drift instead of making duplicates.

## Explicit staging-only apply (after backup and review)

    ./bin/moodle-docker-compose exec -T -e BMS24_STAGING_BRIDGE_ALLOW=1 webserver php local/kbacompany/cli/corporate_bridge_staging.php --companyuuid=b1d324df-b6fe-461a-8d6a-c38ec9dc9c6d --apply

Expected: STAGING_CORPORATE_BRIDGE_OK. Repeat the dry run and verify
that there are zero pending membership insertions.

## Browser checks

- https://test.bms24.online/local/kbacompany/index.php
- https://test.bms24.online/local/kbacorporate/implementation.php

The second URL requires an authorised site admin or company manager. The
script does not grant management rights or bypass Moodle permissions.

## Stop/rollback

Stop on any STOP message, missing company, duplicate membership or role drift.
Inspect and reconcile manually. Use the verified database backup to roll
back the applied bridge; do not casually delete corporate rows because other
corporate tables may reference them.

No production deployment or test-server installation is included in this PR.

## Stage 1881.17: guarded installer (dry-run only)

The GitHub branch contains `tools/staging/install_corporate_bridge_staging.sh`.
To install without WinSCP, fetch the branch and load the installer onto the
staging VDS (run as root). Inspect the file before executing:

    REPO=/srv/bms24-staging/workspace/bms24
    BRANCH=fix/1881-corporate-staging-bridge
    runuser -u bmsdev -- git -C "$REPO" fetch --no-tags origin \
      "refs/heads/$BRANCH:refs/remotes/origin/$BRANCH"
    runuser -u bmsdev -- git -C "$REPO" show \
      "refs/remotes/origin/$BRANCH:tools/staging/install_corporate_bridge_staging.sh" \
      > /tmp/bms24-stage1881-install.sh
    bash -n /tmp/bms24-stage1881-install.sh
    bash /tmp/bms24-stage1881-install.sh

The installer backs up any existing staging CLI file outside the webroot,
installs only the bridge CLI, runs PHP syntax check **inside** the Docker
webserver, and performs the read-only dry-run. On failure after installation,
it restores the former CLI file. **It never executes --apply**.

Do not interpret `DRY_RUN_OK` as proof that `implementation.php` works.
The existing staging snapshot shows the corporate registry empty, so a
legitimate, reviewed `--apply` and browser ACL checks are still necessary.

## Stage 1881.18: verified application with fresh DB backup

**Prefer the new orchestrator**: `tools/staging/stage1881_corporate_bridge_run.sh`.
Install the latest branch version of the PHP CLI first using the stage 1881.17 installer
(it fetches the branch every time). Then fetch the orchestrator into a local script:

```bash
REPO=/srv/bms24-staging/workspace/bms24
BRANCH=fix/1881-corporate-staging-bridge
runuser -u bmsdev -- git -C "$REPO" fetch --no-tags origin \
  "refs/heads/$BRANCH:refs/remotes/origin/$BRANCH"
runuser -u bmsdev -- git -C "$REPO" show \
  "refs/remotes/origin/$BRANCH:tools/staging/stage1881_corporate_bridge_run.sh" \
  > /tmp/bms24-stage1881-apply.sh
bash -n /tmp/bms24-stage1881-apply.sh
bash /tmp/bms24-stage1881-apply.sh --preflight
```

The default `--preflight` is read-only. After reviewing the output, the explicit
`--apply` mode takes a NEW compressed MariaDB snapshot in
`/srv/bms24-staging/backups/corporate-bridge`, validates its gzip stream,
and only then runs `--apply` on the staging Docker webserver. It immediately
runs the new **read-only** PHP `--verify` mode, checking mapping, membership
roles, manager visibility and company-wide access.

```bash
bash /tmp/bms24-stage1881-apply.sh --apply
bash /tmp/bms24-stage1881-apply.sh --verify
```

Expected markers: `STAGE1881_DATABASE_BACKUP_OK`, `STAGING_CORPORATE_BRIDGE_OK`,
`VERIFY_OK`, `STAGE1881_APPLY_AND_VERIFY_OK`. The PHP writer refuses `--apply`
unless Moodle's effective `$CFG->wwwroot` is exactly `https://test.bms24.online`,
SSL proxy is enabled, and `BMS24_STAGING_BRIDGE_ALLOW=1` is passed.

If the legacy registry is already populated in an ambiguous way, the script
fails without changing it. A failed verification after a committed write requires
investigation/DB restore; there is no automated destructive rollback.

Finally run authenticated browser checks on
`/local/kbacorporate/implementation.php` with an entitled company manager
and with an unrelated user. The latter must **not** gain access. These UX checks
cannot be replaced by unauthenticated HTTP status codes.

## Stage 1881.19 — ACL acceptance (read-only)

New files:
- `local/kbacompany/cli/corporate_acl_acceptance_staging.php`
- `tools/staging/stage1881_acl_acceptance.sh`

The acceptance script **only queries** the staging Moodle database. It refuses to
run outside the configured `https://test.bms24.online` environment. It checks:
- One-to-one canonical company UUID to legacy numeric company ID
- Active memberships and all five member role flags match
- Each canonical company manager is granted both company-wide visibility and
  `managecompanyimplementation` (or `manageimplementationaudits`) capability
- Non-manager employees do not inadvertently obtain full implementation access
- An unrelated, nonprivileged Moodle user cannot access the target company

It does not create users, change roles, alter capabilities, or perform DB migrations.

### Staging execution

After stage 1881.18 has completed and printed `STAGE1881_APPLY_AND_VERIFY_OK`:

```bash
REPO=/srv/bms24-staging/workspace/bms24
BRANCH=fix/1881-corporate-staging-bridge
runuser -u bmsdev -- git -C "$REPO" fetch --no-tags origin \
  "refs/heads/$BRANCH:refs/remotes/origin/$BRANCH"
runuser -u bmsdev -- git -C "$REPO" show \
  "refs/remotes/origin/$BRANCH:tools/staging/stage1881_acl_acceptance.sh" \
  > /tmp/bms24-stage1881-acl.sh
bash -n /tmp/bms24-stage1881-acl.sh
bash /tmp/bms24-stage1881-acl.sh
```

Expected after full test data setup:
`STAGE1881_ACL_ACCEPTANCE_OK` and `STAGE1881_ACL_PHP_ACCEPTANCE_COMPLETED`.

If there is no unrelated active Moodle user, the script returns exit code 4 and
`ACCEPTANCE_PARTIAL`; create a normal unrelated **test** account via the
approved Moodle admin interface and rerun the audit. Do not artificially
grant privileges or disable permission checks just to pass this test.

### Mandatory browser checks (not automatable from CLI alone)

1. Login as test manager/admin. Open
   `https://test.bms24.online/local/kbacorporate/implementation.php`,
   confirm it loads and routes to the mapped company.
2. Login as an unrelated ordinary test user and verify that the same company
   ID URL is denied.
3. Verify the BMS Company Core index and workspace still work, with no
   `localhost:18080` redirects or PHP stack traces.
4. Report results in the staging release checklist. Do not merge PR #17 or
   deploy to production until all tests are documented.

A CLI access service assertion is not proof that the authenticated browser
session works. Never turn on public Moodle debugging to inspect errors.
