# BMS24 Stage 1881.15 — Functional acceptance (staging only)

## What the automatic smoke test proves
The Timeweb VDS reported `STAGE1881_SMOKE_OK` on 2026-11-02.
- Eight registered plugin build numbers matched the expected versions, including `local_kbacompany 2026101879` and `local_bitrixsync 2026083634`.
- There is one demo BMS24 company and one member. Staging cron is disabled.
- Each of eight `version.php` files passes PHP lint.
- Moodle's schema checker finishes with `Database structure is ok.` but the smoke run counted **220 XMLDB warning lines**, not necessarily 220 different defects.
- Anonymous HTTP request returned 303 (login redirect): this is not an authenticated UI test.

## Scope and safeguards
Use the Timeweb test Moodle via the existing SSH tunnel and `http://localhost:18080`; **never** use live `edu.kzn.mba` for test writes.
Use only a demonstration company, demonstration users and clearly marked test values.
Do not enable cron, copy production Bitrix24 credentials, send external AI requests, create real Bitrix24 deals, or activate production integrations.
The `local_bitrixsync` plugin is installed but its outbound/synchronization behavior is NOT accepted.

## Stage 1881.15.1 — server preflight
- [ ] Run `tools/staging/stage1881_route_xmldb_preflight.sh` on Timeweb and attach its output.
- [ ] Triage distinct XMLDB definitions, including known `externalorderid`, `externalrowid`, and `duedate` fields, before Production.
- [ ] Confirm GitHub Actions and Moodle warnings are separately tracked.

## Stage 1881.15.2 — authenticated manual scenarios

Use the BMS24 Demo Company selected through `/local/kbacompany/index.php`. The UUID appears in the URL automatically after choosing the company; do not make up another UUID.

| ID | Feature and confirmed route | Action | Expected result |
| --- | --- | --- | --- |
| UX-01 | Workspace `bms_workspace.php` | Open company; switch between tabs and menu links | BMS App Shell visible, no PHP debug warnings, no broken navigation |
| UX-02 | Tasks `tasks.php` | Create a clearly named test task for yourself; open and edit it | Task appears, assignee and status are correct, editor loads |
| UX-03 | Employee link / `employees.php` | Open assignee from test task | Correct employee card opens or clearly explains missing profile |
| UX-04 | Battle Plan `battle_plan.php` | Create a demo weekly battle plan; link a test task | Week and related tasks display consistently |
| UX-05 | Recurring Tasks `recurring_tasks.php` | Create a demo rule and view its details | Rule saves; automatic scheduling is intentionally not asserted with cron disabled |
| UX-06 | Calendar `calendar.php` | Open current and next month | Navigation and visible events work without exceptions |
| UX-07 | Metrics `indicators.php` | Create or edit a **test-only** metric with planned and actual value | Values save and display in the correct company context |
| UX-08 | Architecture `bms_architecture.php`; Processes `processes.php` | Open sections, create a test process if supported | BMS navigation remains available, process opens normally |
| UX-09 | People `people.php`; Learning `learning.php` | Open role and learning sections | No missing class/schema/permission error |
| UX-10 | KBA Theme and Element | Open/create a test Moodle course activity if a sandbox course exists | Theme and course element work; no production data copied |
| UX-11 | AI Assessor / KBA Connect | Open their settings read-only; **do not** activate external services | No preconfigured real credentials, no outbound sync triggered |

## Recording results
For every scenario record `PASS`, `FAIL` or `BLOCKED`; include timestamp, route, concise expected-vs-actual and screenshot **without secrets/personal data**.
When something fails, stop that scenario and log the details before proceeding.
Any CRM network call, production write, unhandled exception, or missing authorization boundary is a release blocker.

## Exit criteria
- Zero critical failures in authenticated demo-company workflows.
- Component and database smoke tests continue passing.
- XMLDB warning inventory is triaged and fixes scheduled without changing Moodle Core.
- Synchronization to real Bitrix24 and Production deployment remain **separate approvals**.
- After acceptance, pin Stage 1881 inventory and source hashes in GitHub without pushing raw production snapshots or credentials.
