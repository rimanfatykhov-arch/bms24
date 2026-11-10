# RC30 recovery and browser acceptance — execution sheet

Status: NOT RUN. This sheet does not authorize backup, restore or publication.
Use with RC30_BACKUP_RESTORE_PLAN.md and RC30_RELEASE69_RUNBOOK.md.

## Environment preparation

Use a separate private host/VM with an isolated network and outbound deny rules
applied before restored code can run. No production DB route, shared mounts,
production domain, external DNS aliases or shared cache/session storage. Bind
browser access to loopback through an approved tunnel. Disable external mail,
webhooks, ERP, payment, AI and scheduled workers in the clone configuration before
bootstrap; preserve original configuration only in encrypted backup storage.
Do not change production configuration. Use synthetic clone-only test accounts.

Required stack: exact Moodle4.1.22 Core and the entire installed sibling/theme
stack from the approved backup, PHP8.1 with production extensions, Percona8.0.46-37
for the first faithful restore. MariaDB10.11 is a separate compatibility rehearsal.
Plugin-only GitHub Actions is already isolated but cannot establish full-stack
recovery. Local Docker is not available in the current PATH; a running recovery
VM/container has not been established. Environment execution remains NOT RUN.

Before authorization, record host identity, isolated network evidence, private
volumes, account quota, free bytes/inodes and limits. Existing host free-space
inventory does not prove user quota or available restore capacity. Required
capacity must cover DB/code/moodledata snapshots, restored copy, temporary DDL,
logs and measured growth; do not assume a fixed multiplier is sufficient.
Proposed private backup location is outside www and must be checked for web aliases,
permissions and quota before use. Do not place dumps in Git or the public webroot.

After separate owner authorization: quiesce all writers, capture a coherent
DB/code/moodledata/configuration set with inventory, encrypted manifests/hashes,
verify integrity and offsite/key recovery. Restore only to the isolated target,
record original versions/counts/hashes, take a clone rollback checkpoint, run
strict DECIMAL preflight, apply exactly the reviewed BMS and Corporate ZIPs,
upgrade, verify data/schema and execute the cases below. Rehearse rollback from
the checkpoint and measure RTO/RPO before requesting production publication.

## Browser cases

For every row record actor, clone URL, source SHA, both ZIP SHA256, UTC, steps,
expected/actual outcome, PASS/FAIL/NOT RUN and private evidence path+hash. Redact
personal data in screenshots. Use two distinct companies A/B, managers MA/MB,
employees EA/EB and a revoked member RA. Baseline reads and counts precede upgrade.
No case may run against edu.kzn.mba. All current results are **NOT RUN**.

| ID | Execute on restored clone | Required result/evidence |
|---|---|---|
| S01 | Login/logout EA, expired session, direct protected URL | Valid login; logged-out request denied; session isolated |
| S02 | Open BMS dashboard as EA/MA; owned and legacy shells | Correct company, no cross-company widgets; capture console/server errors |
| S03 | Open organisation chart, inspect positions and links | Same nodes/counts as pre-upgrade baseline; scoped to A |
| S04 | Inspect employees/positions; create a clone-only employee and manager | Explicit employee1/manager0; historical IDs/roles unchanged |
| S05 | Create/assign/start/close a task, move Kanban card; invalid date/revision | Valid lifecycle works; invalid date/stale revision denied |
| S06 | Draft/approve/close Battle Plan; try as EA/foreign manager | Existing approval and completion constraints hold |
| S07 | Run clone recurring-task worker twice with bounded queue | Correct calendar dates; no duplicate instances or external calls |
| S08 | Inspect metrics/plan-fact and DECIMAL boundary samples | Exact saved precision; invalid values rejected, no truncation |
| S09 | Open effective/previous documents, download attachment, foreign ID | History/content/checksums preserved; foreign download denied |
| S10 | Open process and linked tasks; change clone-only permitted item | Correct links and permissions; no data loss |
| S11 | Create development plan, submit, owner accept; draft/foreign acceptance | Draft cannot activate directly; pending owner transition succeeds; foreign denied |
| S12 | EA requests manager workspace/action URL; RA self-plan access | Manager-only data/actions denied; revoked-member policy explicitly reviewed before GO |
| S13 | A/B direct IDs, AJAX, export, search, attachment and API requests | No cross-company read/write/enumeration; unchanged foreign data |
| S14 | Open Moodle course, enrolment, completion and recommendation | Education/progress retained; only permitted courses and actions |
| S15 | Compare all-table counts/IDs/statuses/files before/after; repeat upgrade | No unexplained changes; defaults0/draft; indexes retained; repeat idempotent |

Any unexpected grant, lost record/file, schema drift or critical error is FAIL/HOLD.
Unknown revoked-member behavior requires a product/security decision, not an
assumed PASS. Domain PHPUnit does not substitute for these route/session checks.

## Release pipeline

Development -> CI -> Integration QA -> Staging (separate authorization) ->
Backup/Restore -> Owner Approval -> Production -> Post-deploy Smoke.
Every transition retains one source SHA, both ZIPs/manifests, matrix and rollback
evidence. A skipped stage is NOT RUN/HOLD, never implicit approval. Current task
does not perform staging, backup/restore, merge or production operations.
