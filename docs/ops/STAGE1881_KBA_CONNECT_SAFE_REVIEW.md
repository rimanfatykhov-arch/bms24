# BMS24 Stage 1881.14.6 — KBA Connect safe installation gate

## Decision
Keep the existing Docker bridge. Do not switch to `internal: true`. Existing SSH tunnel `127.0.0.1:18080`, WireGuard and production Moodle are out of scope.

## Inputs
- Source: `/srv/bms24-staging/import/production-snapshot/source/local/bitrixsync`.
- Target: `/srv/bms24-staging/moodle-test/moodle/local/bitrixsync` (currently absent).
- Expected component `local_bitrixsync`, plugin build `2026083634`.
- Staging Moodle: 4.0.12, PHP 8.0; MariaDB 10.11.
- BMS24 core: build `2026101879`.
- Staging cron disabled (`cron_enabled=0`).
- Do not copy Production `config.php`, tokens, webhooks or database.

## Next operation (read-only)
Run `tools/staging/stage1881_bitrixsync_audit.sh` as root on Timeweb. It validates the expected plugin, parses `db/install.xml`, reads lifecycle/event/task files and lists filenames with potential external integration behavior. It prints no credentials, source contents, URLs or tokens.

The script is **not** an installer. The current Docker bridge can reach the public internet. Do not execute Moodle upgrade or load this plugin into the site until installation hooks and possible outbound actions are reviewed.

## Go/no-go checklist before installation
- [ ] Read `db/install.php`, `db/upgrade.php`, `db/tasks.php`, `db/events.php` and `settings.php` on the trusted VDS. Inspect their call chains for outbound requests and data changes; do not paste secrets into issues or chat.
- [ ] Identify all staging configuration defaults and automatic events (login, course enrollment, user signup, cron, ad hoc tasks). Treat disconnected CRM settings as unverified until proven.
- [ ] Ensure no Production CRM tokens/webhooks, secrets or synced customer records are present. If plugin requires external connectivity to install, stop and refactor behind an explicit staging flag.
- [ ] Verify only staging database and Moodledata backups are present and restorable before changing Moodle. Preserve BMS24 demo company and user.
- [ ] Record SHA-256 of the reviewed source snapshot and approved plugin version; do not publish the production snapshot to GitHub without a full secrets and licensing review.
- [ ] After approval, install into staging only, run Moodle CLI upgrade, schema check and verify `local_bitrixsync` version; audit DB and log changes and confirm no outbound CRM writes.
- [ ] Keep production deployment a separate approved job and credential.

## Rollback principle
Before a Moodle plugin is installed, rollback is only the removal of uninstalled files. Once `admin/cli/upgrade.php` has changed database schema/data, deleting plugin files is **not** rollback: restore a consistent test database + Moodledata + code snapshot. Do not change production.
