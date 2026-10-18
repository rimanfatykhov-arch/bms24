# BMS24 Release & QA Standard — Codex

## Requirements for a real release

- Identify **baseline** by Git commit and plugin `version.php`; record changed file list.
- PHP syntax lint for changed and ideally all plugin files; check target PHP compatibility separately.
- JS syntax/lint for changed JS; RU/EN string key parity and duplicate detection; XMLDB validity; CSS selector scope.
- Domain-level tests with positive/negative authorizations, company scoping, stale revisions, task history, recurring idempotency.
- For DB changes verify Moodle fresh install AND exact previous-version upgrade with migration; no fake pass on static checks.
- For UI changes test `owned` AND `legacy`, KBA and Boost host theme, 1440/390 responsive, real Moodle page and browser console; do not substitute a standalone HTML mock for these.
- ZIP install directory root exactly `kbacompany/`, no nested plugin root, no credentials, CRC and SHA256.
- `git diff --check`, state exact commands/tests PASS and NOT RUN, include rollback instructions and backup before deployment.

## Local quick check

`python scripts/qa_local.py` validates static checks. It **does not validate** Moodle PHPUnit DB, Behat, live DB migration, company ACL, browser UI, cron or production safety. PHP 8.x parser does not assure PHP 7.x compat.

## Release format

Expected when asked to package:
- `INSTALL_ME_local_kbacompany_v1.1.0-rc29_buildXXXXXXXXXX.zip`
- `SOURCE_local_kbacompany_v1.1.0-rc29_buildXXXXXXXXXX.zip`
- `RELEASE_NOTES_BMS24_RC29_buildXXXXXXXXXX.md`
- `ACCEPTANCE_BMS24_RC29_buildXXXXXXXXXX.md`
- `SHA256SUMS_BMS24_RC29_buildXXXXXXXXXX.txt`

Do not claim files exist, links work or deployments succeeded without checking. Record exact plugin build, DB/ACL/privacy impact, tests run, failures, pending staging gates and rollback.
