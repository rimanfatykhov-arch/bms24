# BMS24 — GitHub + Timeweb Cloud staging plan (build2026101874)

**Scope:** design and artifacts only. No servers were provisioned, no deployment
was performed, and no production access was configured by this release.

## Topology

- **GitHub private repo** `bms24`: source of truth; PR review; CI for tests and
  candidate artifacts. Code commits are not authority to deploy to production.
- **Timeweb Cloud `bms24-staging-eu`** (proposed): Ubuntu 24.04 LTS,
  4 vCPU / 8 GB RAM / 80 GB NVMe. This is an **untrusted test environment**;
  use synthetic or properly anonymized data only, never production passwords.
- **Timeweb Cloud `bms24-dev-ai-eu`** (later): isolated code execution and agents.
  Not required while GitHub-hosted CI suffices. If a self-hosted GitHub runner is
  used, prefer ephemeral runners with no reusable staging/production credentials.
- **BMS24.online and Russian clients** (later): separately isolated production
  instances; do not connect their databases to EU development services.

## Step-by-step manual server readiness

1. Select your Timeweb VDS and add **your own SSH public key**, never send a
   private key/token to chat, repository or CI logs.
2. Configure an unprivileged sudo account, automatic security updates, SSH
   key login, firewall with SSH access restricted, HTTPS, time sync, monitoring,
   backup and off-host backup. Retain emergency console access.
3. Run the **read-only** inventory `bash infra/timeweb/staging-preflight.sh`.
   It lists only generic OS/resources/component availability and does not
   change server state or reveal IPs. Missing packages are expected on a new VDS.
4. **Verify exact Moodle and PHP compatibility before installing.** Current
   project plugin specifies `requires=2022041900` (Moodle 4.0 minimum), and
   production PHP 8.1.34 has historically been reported. Moodle 4.0 and PHP 8.1
   should not be assumed to be an officially supported pairing. Select the
   staging stack after checking exact current Moodle build and target upgrade.
5. Deploy a *separate* staging Moodle. Do not overwrite `edu.kzn.mba`.
6. Use an isolated test database and synthetic data. Verify restoration of
   database, `moodledata` and plugin/theme files before integration tests.
7. Build verified plugin ZIP using GitHub Actions; manually review manifest and
   SHA-256. Upgrade Moodle in test environment; run PHPUnit/Behat, role/tenant
   isolation checks, Kanban, task forms, plans, dashboards and RC30 preflight.
8. Collect CI job URL, Git commit, ZIP hash, staging URLs, test evidence,
   QA/security findings and rollback proof. Human release approval is required.

## CI candidate release rules

- The GitHub workflow contains **no SSH secrets and no deployment job**.
- Pull requests run checks, but **only pushes to `main` upload candidate ZIPs**.
- The archive is `kbacompany/` with a Moodle `version.php` and SQL upgrade
  savepoint, excludes all `.py` development files.
- The candidate manifest lists each file name, SHA-256 and size.
- `tools/verify_install.py` verifies version, hashes, filenames, structure,
  duplicate entries, path traversal, unexpected Python source and size limits.
- CI artifact retention is 14 days; keep release backups separately.
- **Never install** a CI archive to production directly. It is not RC30
  acceptance and has no production deployment authorization.

## Production approval before deployment

- Ensure the exact code being installed passed staging Moodle integration tests;
  CI lint alone does not establish runtime compatibility.
- Verify scope permissions: company isolation and business-role ACLs.
- Restore test backup on staging; document rollback procedure for upgrade path.
- Record an independent QA/security review and an explicit product-owner signoff.
- Maintain client-specific isolation and Russian data processing compliance.
