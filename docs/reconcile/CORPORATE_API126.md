# Corporate API 1.26 dependency candidate for PR #42

Status: source candidate, NOT accepted or deployed. Base: PR #42 commit `3f000449498ea3c81d563248d4c99a749bed1994`. Branch: `fix/pr42-corporate-api126`; review against `reconcile/pr8-preserve-main`.

## Decision

Use Corporate 0.40.10 / build 2026102610 / bridge API 1.26 with the three explicitly recorded local corrections. BMS remains build2026101879 / RC29. No RC30 promotion. Corporate is optional globally; API 1.26 is required for the implementation integration acceptance scope, not a new unconditional Moodle plugin dependency.

Existing local Downloads archives were inspected as source, not installed. 0.40.3/4 expose API1.22; .5 API1.23; .6 API1.24; .7 API1.25; .8 through .13 API1.26. Choose .10 over .8/.9 because it includes curator company/audit isolation and escaping corrections. Later .11/.12 add curator/Kanban pagination and .13 diagnostics; those unrelated changes are excluded. Archive presence and filenames do not establish signed upstream provenance or any server state.

Source archive: `INSTALL_local_kbacorporate_0.40.10_build2026102610.zip`, SHA256 `85ec38d0292543e9ad87572a091529ae765c7901015044914951ccac6e22e141`. Complete 817-file inventory, per-entry archive/source hashes, normalization, relocations and patch descriptions are in `corporate-api126-source.json`. This is a patched source candidate, not byte-identical redistribution of the archive. Before a future published release, assign a fresh monotonic Corporate build if build2026102610 was already installed anywhere.

## Corrections following independent review

- Single-task projection now checks company management/employee membership before reading task content. Employee SQL includes owner ID; company/deleted/paid-program predicates and post-query guard remain.
- Two Moodle PHPUnit assertions now expect actual API1.26 instead of stale1.23.
- Three standalone fixtures moved from PHPUnit test discovery to cli; CI executes them explicitly.

The preservation gate still protects every original main path and other sibling plugins. Corporate changes are permitted only for inventory-listed files with matching normalized SHA256. Missing, tampered and extra Corporate files fail. No main file deletions are proposed. Historical `contract_gaps` describes the original PR42; `candidate_resolution` describes this branch.

## Impact and acceptance limits

`db/install.xml` unchanged. Upgrade additions after baseline2026092111 contain savepoints only. Moodle still needs to register new `local/kbacorporate:curateimplementation` capability; no automatic role creation or assignments. Role creation is a separate admin action protected by login, role:manage, POST and sesskey. No schema migrations, server configuration or database commands were executed here.

New projections disclose whitelisted task/program/section/result metadata. Evidence bodies, comments and attachments are excluded from result projection. This does not prove complete tenant isolation at runtime.

## Validation

Automated source gate and regressions cover actual seven-method bridge signatures, real BMS provider availability, rejection of original API1.21, inventory tampering/missing/extra files, and query boundaries with access/DB doubles. Four supplied fixtures cover sections (19), task sections (12), evidence results (53), curator scope (17). CI also lints Corporate PHP and runs retained BMS gates.

NOT RUN: real Moodle PHPUnit, HTTP role/ACL tests, MySQL/MariaDB fresh install or upgrade, browser legacy/owned acceptance. Query spies and pure fixtures do not substitute for these checks. Independent source QA/security were requested; final review status is recorded in the PR.

## Safe next steps

1. Review this stacked PR before incorporating it into PR42; do not merge automatically.
2. Confirm actual Moodle/PHP/database versions and installed Corporate build using an explicitly authorized read-only environment inventory. Never downgrade a later installed Corporate build to this candidate.
3. Prepare a disposable Moodle test database with two companies, employees, managers and curator-only users. Run both plugin PHPUnit suites, own/foreign task queries, shared-audit program selection, evidence batching/latest-version and cap/incomplete behavior. Test MySQL and MariaDB separately for the intended supported versions.
4. Verify optional-provider absence/old-provider failure modes, capability registration without assignment, explicit company bindings, and both UI modes. Retain HOLD on integration/RC30 acceptance until these pass.
5. Only after explicit deployment authorization prepare database/files backup and a matched plugin pair. Rollback must restore both code and matching database state; never edit Moodle version records to force a downgrade.

Production/staging untouched; no PR merged.
