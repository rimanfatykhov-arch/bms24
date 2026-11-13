# Issue44 — approved baseline, inventory and three-field repair

Status: **source repair candidate / deployment HOLD**. Owner approved ADR-0002 and
its integration order on2026-11-08. This PR builds on PR47; no PR is merged.
The approved starting branch `integration/rc30-baseline` points to
`a36c5ab8ac129433eeddb0ae341717e35b614ac8`; the repair is isolated in
`fix/issue44-schema-parity`. Historical Issue44 report remains a dated observation.

## Read-only evidence, not assumed parity

The live [stage41 inventory](issue44-stage41-inventory.json) was collected using
`tools/inventory_moodle_readonly.php` streamed through SSH into the existing web
container. No script was written on stage41, no Moodle config/setup was executed,
and no session, cache, schema or application data was changed. The script parses
literal config values (including an absolute local password-file reference),
authenticates without printing secrets, opens a READ ONLY transaction and reads
version/config and information_schema metadata. It is not a general Moodle bootstrap.

| Contour | Observed | Limit |
| --- | --- | --- |
| stage41 | Moodle4.1.22/core2022112822; PHP CLI8.1.34; MariaDB10.11.19; utf8mb4_unicode_ci | PHP web SAPI version, backup/restore and source Git SHA not established |
| stage41 BMS | Code and DB2026110802/RC29 | Newer feature tree than approved Git1879 baseline |
| stage41 Corporate | Code and DB2026102613/0.40.13 | **Do not install candidate2610 over2613** |
| stage41 fields | task.starttime and duetime already varchar5, NOT NULL, empty default; deviation.executionstatus absent | Metadata only; task data not read |
| test.bms24.online | Nginx points to127.0.0.1:18080; stage1880 web/db containers stopped2026-11-05 | Not started; installed DB/plugin versions NOT RUN |
| edu.kzn.mba | Moodle4.1.22; PHP CLI8.1.34; Percona Server8.0.46-37 (MySQL8.0 family); BMS code/DB2026101878; Corporate code/DB2026102613 | Web SAPI, backup/restore and full source Git SHA not established |

Docker image tags are supplementary evidence, not exact PHP/DB versions. Moodle
physical index names differ from XMLDB names; inventory retains ordered columns
for all indexes on task/tasklog/bplanitem instead of declaring logical indexes
missing merely because their names differ. It does not replace Moodle index_exists
or performance tests. Exact ordered-column comparison found task(companyuuid,status,duedate) absent, with task(companyuuid,duedate,status) present instead; the other three expected ordered column sets exist. This index-order difference is a separate performance/acceptance risk, not changed by this repair. Collected hashes identify individual source files, not a full
deployment Git commit or an approved artifact.

## Narrow SSH authorization exception

After the original no-server-change request, the owner explicitly instructed:
«настрой SSH-маршрут stage41→RUWEB сейчас». This authorizes route setup only.
On stage41 a separate Ed25519 key and isolated SSH config were created:
`/root/.ssh/bms24_ruweb_inventory`, `/root/.ssh/bms24_ruweb_inventory.conf`.
The private key remains only on stage41. StrictHostKeyChecking, BatchMode,
IdentitiesOnly and ClearAllForwardings remain enabled; no general config was replaced.
The alias `ruweb-inventory` is used through `ssh -F` with that isolated config.

The route is **ACTIVE and verified**. The existing saved WinSCP RUWEB session
was used for authorized setup without extracting its password. On RUWEB the owner-only
`.bms24-inventory` directory sits outside WWW. Its fixed shell wrapper accepts exactly
`inventory`, executes the fixed `/usr/bin/php` script against the confirmed Moodle root,
and closes stdin. The added authorized_keys entry has `restrict`, `from` limited to
stage41 and forced command; previous entries are preserved with a private backup.
No unrestricted shell key was added. Scripts received independent security review.

End-to-end: stage41 alias `ruweb-inventory inventory` returned the saved
[production inventory](issue44-production-inventory.json); `ruweb-inventory id`
returned DENIED/exit2. Host key verification remained strict, matching the previously
trusted host key. Private key remains on stage41 and is not in this repository.
Both live inventories have the two task-time fields and lack deviation.executionstatus.
Both have the task index in companyuuid,duedate,status order rather than the RC30
expected companyuuid,status,duedate order. Production is **not MySQL5.7**: actual
Percona8.0 needs its own compatibility/performance row and cannot inherit MariaDB results.
The hosting mysqli client emitted a MYSQL_OPT_RECONNECT deprecation warning; inventory
still completed. The script uses bind_result rather than mysqlnd-only get_result.

Applications and databases on production/staging remain unchanged. The explicitly
authorized infrastructure writes are the dedicated stage41 SSH key/config and the
restricted RUWEB key/handler/backup, not application deployment or database repair.

## Repair semantics

`db/upgradelib.php` contains the frozen helper called by new checkpoint2026110890:

- Preflight both tables and definitions of all three existing columns before DDL.
- Add only absent task.starttime/duetime (char5, NOT NULL, default empty) and
  deviation.executionstatus (char16, NOT NULL, default pending), matching install.xml.
- Preserve all existing values, including tenant UUIDs, dates and workflow status.
- Reject incompatible type/length/nullability/default; no silent conversion.
- Re-read actual metadata after DDL and savepoint only after success. A partial DDL
  attempt is retryable. Missing required tables stop the upgrade.
- No old checkpoint is rewritten; no UPDATE, DROP, role or capability change.

The fresh schema already declares these fields, so install.xml is unchanged.
The absence of a known deviation consumer does not justify deleting its declared
field; the repair makes the two installation paths agree without changing values.

## Candidate identity and operational limits

Build2026110890/RC29 is reserved for this review candidate and is greater than the
observed stage41 build2026110802. It also exceeds observed production1878. The stopped test database was not opened,
so monotonicity against every possible environment is **not established**. Never use the higher
number to install this older feature tree over stage41. Later Role Intelligence/
competency changes must be reconciled separately and the repair ported to that tree.
Corporate source is intentionally unchanged by this PR; no downgrade authorized.

version.php, final savepoint, strict EXPECTED_PLUGIN_VERSION, dev/source/workflow
gates and CI artifact identity change together. Historical importer fixtures still
describe import1879. The old test filename test_rc30_version_1879.php now validates
the current candidate identity; mismatches still fail. RC30 promotion stays false.

## Validation and acceptance

PASS: standalone actual-upgrade-entrypoint regression, 227 assertions (1645,1879,
2026110802 ×8 field-presence states; retry, compatible data, incompatible definitions,
missing tables, failed/incomplete DDL and savepoint ordering). Historical surrounding
upgrade operations are modelled by doubles; this is not full migration execution.

The repeatable isolated real-MariaDB test is in `tools/tests/` and uses read-only
Moodle4.1 DML/XMLDB source with a new local DB process/datadir/port. It never uses
the shared Moodle config, running DB or PHPUnit database. MUC/localization helpers
are substitutes; DDL, metadata, SQL and the repair helper are real. Actual result: **320 assertions PASS**, PHP8.1.34/Moodle4.1.22/MariaDB10.11.11.
See [saved log](issue44-real-xmldb-results.txt). The owned DB process was stopped;
the pre-existing shared DB process was left running.

Local importer tests: two symlink-creation cases fail with Windows WinError1314;
no test was weakened. Linux CI must cover those cases. Local artifact tests11/11 and
workflow policy tests12/12 PASS. Also required: PHP lint, version/runtime regressions, workflow negative tests,
source-preservation gate and deterministic packaging. CI result belongs to the exact
PR SHA; green CI cannot remove the deployment HOLD above.

NOT RUN unless separately evidenced: MySQL5.7/Percona8.0 migration execution, complete Moodle fresh1645→candidate
upgrade, full Moodle PHPUnit, server repair, web/tenant ACL, browser acceptance,
backup/restore and performance. Minimal-table XMLDB integration does not cover
all198 historical migrations or all142 tables.

## Next actions and rollback

Restricted RUWEB SSH authorization and live metadata inventory are complete.
Keep stopped test untouched.
Reconcile the observed newer stage41 feature tree and Corporate2613 before choosing
the final deployable plugin pair. Then full isolated Moodle/DB acceptance, reviewed
artifacts and explicit deployment authorization. Issue44 remains open.

Source rollback: revert this PR. Future deployed schema rollback requires matched
backup and tested restore, not decreasing config_plugins.version or dropping fields
with potentially populated data. The new SSH route can be revoked by removing only
its marked authorized_keys line and dedicated client config/key, after checking
no unrelated key or alias is affected; no existing routes need alteration.
