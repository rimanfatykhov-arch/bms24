# RC29 build2026101817 — Corporate implementation stages in linked BMS Project

- Read-only stage drill-down for an existing, currently published BMS Project linked to a Corporate paid program.
- Corporate 0.40.6 / bridge API 1.24 exposes only sanitized section statistics based on the confirmed baseline audit.
- Status and count indicators distinguish tasks completed, tasks under review, overdue tasks, criteria gaps and accepted evidence.
- BMS verifies company binding, user permissions, explicit project mapping and current project publication before fetching data.
- No new database tables, no task copies, no write-back, no synthetic changes to BMS Project status.
- Company/curator contact details, task discussions, audit raw items and evidence content are never exposed by the bridge.
- Detail page is lazily fetched only when selected; program overview paging remains unchanged.
- Corporate owns confirmation/acceptance and all original task data.

## Install and staging acceptance
1. Backup database and code; upgrade Corporate 0.40.6 (2026102515) FIRST and BMS24 2026101817 SECOND.
2. Complete Moodle database upgrade, purge caches, and confirm two version numbers.
3. Verify linked published project displays stage summaries; compare numbers with Corporate section progress page.
4. Verify no baseline and empty sections display explicit notice, not false zero percent.
5. Verify other-company UUID/programid tampering, unlinked program, stale project, manager without Corporate rights fail closed.
6. Verify project/task status, evidence and discussion remain unchanged.
7. Perform PHP 8.1 / Moodle 4.1 / MySQL acceptance and performance measurements; not completed in the local build.

## Deliberate limits
- Sections are Corporate audit categories, NOT new Administrative Scale ProgramStep objects.
- No editable stage plan or stage-to-Battle-Plan assignment in this release.
- Section counters are factual task/evidence aggregates and NOT verified company BMS maturity or implementation completion.
