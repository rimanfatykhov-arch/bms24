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
