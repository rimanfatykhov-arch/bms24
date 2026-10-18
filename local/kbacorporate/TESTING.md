# KBA Corporate Dashboard — acceptance checklist

## v0.17.1 My Competencies release gate

1. Upgrade a database copy from production build `2026082410` directly to `2026090100`; confirm the v0.17.0 role tables are created first and all companies, memberships, courses and `mycompetencies.php` data remain intact.
2. As an ordinary authenticated learner without `local/kbacorporate:viewowncompetencies`, open the own `mycompetencies.php` page and confirm it remains available. Viewing another employee must still require `local/kbacorporate:viewcompetencies` plus company visibility.
3. For a learner with one active company, confirm the company is selected automatically and no redundant selector is shown.
4. Assign one employee to Company A and Company B, with different business roles and competency targets in each. Open `mycompetencies.php` without `companyid`; confirm the page asks for a company and does not merge role targets.
5. Select Company A and then Company B; confirm roles, target levels and recommended courses change with the selected company and never include the other company's data.
6. As a Company A manager, open the employee competency view and confirm Company B course names/evidence are absent. Attempt a manual Company B `companyid` and confirm access is denied.
7. Create three competency cases: no user grade, stored grade `0`, and authoritative grade at target. Confirm statuses are respectively `Not assessed`, `Not started` and `Target achieved`.
8. Confirm current and target level labels come from the competency/framework scale configured in Moodle; the built-in KBA labels are only a fallback when that scale is unavailable.
9. Add a course-scoped competency grade without a user competency grade. Confirm it is shown as provisional evidence and status `Needs confirmation`; current level remains `Not assessed`.
10. Map active Company A courses to the competency. Confirm recommendation order: in progress, enrolled/not started, available, completed. Confirm duplicates from multiple company group mappings are removed and hidden/unlicensed/unavailable courses are absent.
11. Complete a recommended course and confirm course progress changes but the authoritative competency level does not change automatically.
12. Confirm latest assessment/confirmation uses `Audit` only when evidence has an explicit audit marker; AI evidence uses `AI Оценка`.
13. Remove only one corporate access role while membership persists; confirm business-role assignments persist. Delete the membership/company and confirm related assignments/mappings are deleted transactionally without FK errors.
14. Run Privacy API metadata, export, delete-user, user-list and delete-all tests for `local_kbacorp_member_busrole` and user rows in `local_kbacorp_extmap`.
15. Confirm `bridge::contract()` reports API `1.15`; catalog `companyid=0` returns global roles only; ambiguous target-profile calls return `company_context_required`.
16. Confirm the reserved capability `local/kbacorporate:managerolecompetencies` exists but does not by itself grant cross-company visibility.
17. Run all plugin PHPUnit tests and PHP lint on the target Moodle/PHP stack. Do not package if any test fails.

### Target Moodle command sequence

Run this on a disposable Moodle clone with the copied production database, not on production:

```bash
find local/kbacorporate -type f -name '*.php' -print0 | xargs -0 -n1 php -l
php admin/tool/phpunit/cli/init.php
vendor/bin/phpunit --testsuite local_kbacorporate_testsuite
php admin/cli/maintenance.php --enable
php admin/cli/upgrade.php --non-interactive
php admin/cli/purge_caches.php
php admin/cli/maintenance.php --disable
```

After the command sequence, complete acceptance items 2–16 above and run Moodle cron plus the existing KBA Connect bridge smoke tests. Preserve the command output in `TEST_REPORT_0.17.1.md` before creating the production ZIP.

## 1. Installation

- Install ZIP as a local plugin.
- Confirm three tables are created: `local_kbacorp_company`, `local_kbacorp_member`, `local_kbacorp_course`.
- Confirm no PHP error appears on Site administration -> Notifications.

## 2. Company setup

- Open Site administration -> Plugins -> Local plugins -> KBA Corporate Dashboard -> Companies.
- Create Company A and Company B.
- For Company A add one Moodle user as `Corporate manager` and two users as `Employee`.
- For Company B add a different manager and employee.

## 3. Course setup

- Assign a course to Company A without a group.
- Assign another course to Company A with a Moodle group.
- Confirm only employees in that group appear for the group-specific mapping.

## 4. Access isolation — critical

- Log in as Company A manager.
- Open `/local/kbacorporate/` and confirm Company B is not present in the company selector.
- Try manually opening `/local/kbacorporate/index.php?companyid=<COMPANY_B_ID>` and confirm access is denied.
- Try the same with `employee.php` and `export.php` for Company B and confirm access is denied.

## 5. Progress and grades

- Complete at least one tracked activity as an employee.
- Confirm progress changes from 0 to the expected percentage.
- Add/update the course total grade and confirm the dashboard shows the percentage.
- Complete the course according to Moodle course completion rules and confirm status becomes `Completed`.

## 6. Export

- Log in as the Company A manager.
- Download CSV.
- Confirm the report contains only Company A employees and courses.
- Confirm columns include company, employee, email, phone, position, course, status, progress, grade, enrolment date, last activity.

## 7. Manager removal

- Remove Company A manager from the company.
- Confirm the plugin-created role assignment is removed for that company membership.
- Confirm the former manager cannot open the corporate dashboard unless they still manage another company or hold another role granting the capability.


## 8. BigBlueButton attendance (v0.3.0)

- In a company course, add a BigBlueButton activity.
- If desired, configure the BBB completion rule requiring a minimum number of attendance minutes.
- Join the meeting as a company employee and let the meeting finish so Moodle receives the BBB summary callback.
- Run Moodle cron if the BBB summary/completion data has not appeared yet.
- Open Corporate dashboard -> Webinars and confirm the employee shows `Attended` and a non-zero presence time.
- If the employee attended for less than the configured minimum, confirm status is `Attended, minimum not met`.
- For a second employee who did not join a webinar that has already produced Summary data, confirm status is `Missed`.
- Confirm upcoming webinars are not counted in the attendance-percentage denominator.
- If the company-course mapping uses a Moodle group, confirm the Webinars page only evaluates employees in that group for that mapping.
- Open the employee card and confirm the webinar subsection is visible only to users with `local/kbacorporate:viewattendance`.
- Download CSV and confirm webinar assigned/attended/time/attendance columns are present.

## v0.4.0 learning-time checks
1. Upgrade from v0.3.0 and confirm the company table gains `trainingminutesday` and `trainingweekdays` without losing existing companies.
2. Edit a company and set 1.5 hours/day, Monday-Friday.
3. Open **Corporate dashboard -> Learning time** as a corporate manager.
4. Verify current-week plan equals: selected employees × selected learning days × 1.5 hours.
5. Generate activity in an assigned Moodle course and confirm estimated Moodle hours appear after log records exist.
6. For a completed BigBlueButton webinar with Summary data, confirm webinar time is shown separately and included in total actual hours.
7. Check cumulative plan/fact values by day and the average actual time per employee.
8. Filter to one employee and verify only that employee is included in plan and fact.
9. Download the learning-hours CSV and verify plan, Moodle estimate, webinar time, total and percentage.
10. Confirm an ordinary employee cannot access the corporate learning-time page directly.

## BMS Connect bridge smoke test (v0.4.2)

1. Confirm Moodle detects the API:
   `class_exists('\\local_kbacorporate\\local\\bridge')` and `method_exists(..., 'sync_member')` must both be true.
2. Call the bridge with an existing Moodle user, a non-zero Bitrix24 Company ID and a company title.
3. Confirm exactly one `local_kbacorp_company` record is used for that `bitrixcompanyid`.
4. Confirm exactly one `local_kbacorp_member` relation exists for `companyid + userid + membertype=employee`.
5. Repeat the same call and confirm no duplicate company or employee relation is created.
6. Change only the company title and repeat; the existing company name should update without creating a new company.
7. Verify the corporate dashboard, progress, BigBlueButton and learning-time pages still behave as before.


## Public API v1 smoke test (v0.4.4)

1. Upgrade to v0.4.4 and purge Moodle caches.
2. Open **Site administration → Plugins → Local plugins → KBA Corporate Dashboard → Public API / diagnostics**.
3. Confirm API version `1.0`, database schema `Yes`, and no unexpected duplicate Bitrix24 Company IDs.
4. Confirm `bridge::contract()` lists `sync_company`, `sync_employee`, legacy `sync_member`, `remove_employee`, and `assign_course`.
5. Existing BMS Connect detection using `class_exists('\\local_kbacorporate\\local\\bridge')`, `method_exists(..., 'sync_member')` and `bridge::status()` must continue to work unchanged.
6. Re-sync an existing employee twice: the second call must not create duplicate companies or member records.
7. Map the same company/course/group twice with `assign_course()`: the second call must return the same mapping and must not duplicate it.
8. Call `get_company_by_bitrix_id()` with an unknown valid ID: it must return `found => false` instead of exposing a DB exception.


## Public API v1.1 / company-course snapshot smoke test (v0.4.5)

1. Upgrade from either canonical v0.4.4 (`2026081500`) or the parallel snapshot build (`2026081501`) to v0.4.5 (`2026081502`) and purge Moodle caches.
2. Open **Site administration → Plugins → Local plugins → KBA Corporate Dashboard → Public API / diagnostics** and confirm API release `1.1` and schema status OK.
3. Confirm `bridge::contract()` still lists all API v1 write/read methods and now also lists `get_company_snapshot` and `list_company_snapshots` with `since = 1.1`.
4. Existing BMS detection using `class_exists`, `sync_member()` and `status()` must remain unchanged.
5. For a company linked by `bitrixcompanyid`, call `get_company_snapshot()` and confirm that only courses assigned through KBA Corporate Dashboard are returned.
6. Confirm a hidden Moodle course or inactive corporate mapping is returned with `active = false` and is not counted in `activecoursecount`.
7. Call `list_company_snapshots(0, 100)` and verify results are ordered by local `companyid`; use the highest returned `companyid` as the next cursor.
8. Re-run existing idempotency tests for `sync_employee()` / legacy `sync_member()` and `assign_course()`.


## v0.5.0 Public API v1.2 / BMS full sync

1. Open `/local/kbacorporate/api.php` as an administrator. Confirm API release `1.2`, schema OK and `API writes are safe = Yes`.
2. Call `bridge::sync_training_assignment($userid, $bitrixcompanyid, $title, $courseid, $groupid, $contactid)` twice with identical values. The first call may report created/changed; the second must return success with no duplicate company, member or course mapping.
3. Call the same method with a renamed company title. Only the company name should change; membership and course mapping must remain single rows.
4. Call `bridge::unassign_course(...)`; the mapping must become inactive. Call `bridge::sync_training_assignment(...)` again; the same mapping must reactivate.
5. Call `bridge::sync_company_courses(...)` with two mappings and `deactivatemissing=true`; mappings omitted from the supplied set must become inactive, not be deleted.
6. Confirm `bridge::get_company_snapshot()` returns `employeecount`, `managercount`, active/inactive course metadata and does not infer the company catalogue from Moodle enrolments.
7. Confirm existing `sync_member()`, `sync_employee()`, `assign_course()`, `get_company_snapshot()` and `list_company_snapshots()` calls still work.
8. Confirm Moodle enrolment is unchanged by every Corporate Dashboard bridge method; enrolment remains BMS Connect responsibility.


## v0.6.0 Learning Time Analytics 2.0

1. Upgrade from v0.5.0 and confirm Moodle performs only the `2026081504` savepoint; no new fields/tables should be created.
2. Open **Corporate dashboard → Learning time** and test all period presets: current week, current month, current quarter and custom.
3. Confirm week/month/quarter end today and do not include future planned weekdays in the plan.
4. For a period with multiple weeks, verify the weekly table totals match the sum of the daily rows and overall summary.
5. Verify company variance equals `Fact - Plan` and that negative/positive variance is styled accordingly.
6. Verify each employee has the same variance calculation and `Plan met` at 100% or more, otherwise `Below plan`.
7. Confirm `Employees below plan` equals the count of employee rows with `Below plan`.
8. Confirm course time-share percentages are based on total actual learning time.
9. Download all four CSV scopes (employees, weeks, courses, days) and verify period/filter values are preserved.
10. Open Public API diagnostics and confirm API release `1.3` and method `get_training_time_snapshot` is listed.
11. Call `bridge::get_training_time_snapshot($bitrixcompanyid, 'month')` for a linked company and verify its summary matches the UI for the current month.
12. Repeat legacy v1.0–v1.2 BMS bridge smoke tests to confirm backwards compatibility.


## BigBlueButton Analytics 2.0 checks (v0.7.0)

1. Upgrade from v0.6.0 and confirm no database schema migration is requested beyond the normal plugin savepoint.
2. Open plugin settings and verify BBB late tolerance = 10 minutes, early-leave tolerance = 10 minutes, and maximum single-webinar window = 720 minutes by default.
3. Use a BBB activity with a 60–180 minute open/close window and finish a meeting so Summary data is available.
4. Confirm Corporate dashboard → Webinars shows factual duration, presence %, first join (when Join log exists), lateness, and engagement counters.
5. Confirm `Summary.data.engagement` values (chat/talk/hand/poll/reaction) are reflected in the engagement cell and totals.
6. Create/use a BBB activity whose open/close interval spans several days. Confirm presence % and lateness show `—` rather than treating the multi-day access window as meeting length.
7. When a compatible Logout log exists, verify last logout/early-leave values in the webinar CSV; when it does not exist, those fields must remain blank rather than being estimated.
8. Download the dedicated webinar analytics CSV and confirm it includes factual duration, calculated window duration, presence %, Join count, lateness and engagement fields.
9. Confirm the general company CSV now includes average webinar coverage, late count and engagement event count.
10. Open Public API diagnostics and confirm API release `1.4` and the BigBlueButton availability row.
11. Call `bridge::get_webinar_analytics_snapshot($bitrixcompanyid)` and confirm it returns company summary + per-employee aggregates without exposing raw DB records.
12. Confirm a corporate manager without `viewattendance` cannot access `webinars.php` or `export_webinars.php` directly.

## Certificates & document registry (v0.8.0)

1. Upgrade from v0.7.0 and confirm Moodle performs only the `2026081506` savepoint; no local tables/fields are added.
2. Confirm `/local/kbacorporate/certificates.php?companyid=<id>` shows an informational message when `mod_customcert` is absent and the rest of Corporate Dashboard remains usable.
3. With `mod_customcert` installed, assign a Moodle course containing a Custom certificate activity to Company A.
4. Enrol a Company A employee in the course. Before issue, confirm the registry shows `Not issued`; do not interpret this as eligibility.
5. Issue/download the certificate normally as the employee so `customcert_issues` contains a record. Confirm Corporate Dashboard changes the row to `Issued`, shows the issue date/code and increments the issued KPI.
6. As Company A manager, use **Download PDF** and confirm the employee's already-issued certificate is generated. Confirm no new `customcert_issues` row is created by Corporate Dashboard.
7. Manually change `companyid` or `issueid` to a Company B certificate and confirm access is denied.
8. For a group-specific company-course mapping, confirm certificates are only exposed for employees currently in the mapped Moodle group.
9. Filter the registry by employee, course and issued/not-issued state; export CSV and verify only filtered rows are present.
10. Open the employee card and confirm its certificate summary/table is visible only with `local/kbacorporate:viewcertificates`.
11. Open **Public API / diagnostics** and confirm API release `1.5` and Custom Certificate availability status.
12. Call `bridge::get_certificate_registry_snapshot($bitrixcompanyid)` and confirm it returns summary/documents without creating issues or changing certificates.


## Administrator version information (v0.8.1)

1. Upgrade from v0.8.0 and confirm Moodle records savepoint `2026081507`; no local schema changes occur.
2. Open Site administration -> Plugins -> Local plugins -> KBA Corporate Dashboard -> Version information.
3. Confirm the table shows release `0.8.1`, component `local_kbacorporate`, build on disk `2026081507` and database version `2026081507`.
4. Confirm the page shows a green synchronisation status when disk and DB versions match.
5. Confirm Public API diagnostics also shows the release/build/database version values.

## v0.9.0 Report Center
1. Open Corporate Dashboard as a corporate manager and choose a company.
2. Open **Reports** and test week/month/quarter/custom period filters.
3. Download the management PDF and confirm Cyrillic text renders correctly and tables paginate.
4. Download the XLSX workbook and confirm six sheets are present: Summary, Employees, Progress, Learning time, Webinars, Certificates.
5. Verify a manager cannot export another company's report by changing `companyid` in the URL.
6. Open Public API diagnostics and confirm API release 1.6 includes `get_management_report_snapshot()`.

## v0.10.0 department access test

1. Create two departments in one company, e.g. Sales and HR.
2. Assign different employees to each department.
3. Assign a normal Moodle user as manager of Sales only.
4. Log in as that user: Corporate Dashboard must be visible, but only Sales employees must appear in Employees, Progress, Learning time, Webinars, Certificates and exports.
5. Try opening an HR employee URL directly; access must be denied.
6. Log in as the company corporate manager: both departments and all employees must be visible.
7. Export PDF/XLSX/CSV with a department filter and confirm only selected department employees are included.


## v0.11.0 learning-control and notification checks

1. Upgrade from v0.10.1 and confirm Moodle adds `alertsenabled`, `alertfrequency` and `lastalertsent` to `local_kbacorp_company`, registers the message provider and installs the scheduled task.
2. Open the plugin settings and verify the default thresholds: start course 3 days, inactivity 7 days, progress 50%, grade 60%, learning-time plan 80%, webinar attendance 70%, digest minimum severity Medium.
3. Edit a company, enable the digest and choose Daily or Weekly. Confirm the company list displays the notification state.
4. Create examples for each signal type: assigned but not enrolled; enrolled but not started; inactive; low progress; low grade; below learning-time plan; low webinar attendance; completed without certificate.
5. Open **Corporate Dashboard → Requires attention** and verify severity/type/employee/department/period filters and summary counters.
6. Download the control-signals CSV and confirm it contains only the visible and filtered rows.
7. Log in as a company corporate manager and confirm all company signals are visible.
8. Log in as a department manager and confirm only employees from assigned departments are visible in the page, CSV and notification digest.
9. As an administrator, use **Send notifications now**. Confirm a Moodle popup/email notification is created for recipients with signals at or above the configured minimum severity.
10. Confirm recipients without matching signals do not receive an empty message, while the company run is still timestamped when no errors occur.
11. Run `local_kbacorporate\task\send_attention_digest` from scheduled-task administration and verify the task summary counts companies, due companies, recipients, sent, empty, skipped and errors.
12. Open Public API diagnostics and confirm API release `1.8`, attention availability and companies with notifications enabled.
13. Call `bridge::get_attention_snapshot($bitrixcompanyid, 'month')` and compare the returned summary/employees/alerts with the UI.
14. Resolve one learning issue and confirm its calculated signal disappears without deleting any alert record.

## v0.12.0 corporate licence, seat and API checks

1. Upgrade from v0.11.0 through **Site administration → Notifications**. Confirm build `2026081513` creates `local_kbacorp_license` and `local_kbacorp_license_user` without XMLDB key/index collisions.
2. Confirm every existing `local_kbacorp_course` mapping receives exactly one unlimited `legacy` package. Re-run/resume the upgrade logic in a test copy and confirm no duplicate package or allocation is created.
3. For an existing company employee enrolled in a mapped course, confirm one compatibility allocation is created. For a group-specific mapping, confirm only employees in that Moodle group are backfilled.
4. Open **Companies → Licences** and create a finite manual package. Verify course/group validation, non-negative seats and start/end date validation.
5. Select source **Bitrix24 / BMS Connect** without Deal ID or Product Row ID and confirm validation rejects the record. Confirm duplicate external references are also rejected.
6. Allocate seats to employees up to the package limit. Confirm the next allocation fails with `licensefull` and no unlimited legacy package is used to bypass the real contract.
7. Repeat an allocation for the same employee/course/group. Confirm it is idempotent and does not consume a second seat, including when another package for that course exists.
8. Release an employee who has not started training and confirm the free-seat count increases. Start or complete the course and confirm release is refused.
9. Try changing the course/group of a package that has active allocations. Confirm the change is blocked; quantity, dates and status may still be updated.
10. Test future, active, full, overbooked, expired, inactive and cancelled packages. Confirm current company KPIs exclude expired/inactive/cancelled capacity while package history remains visible.
11. Confirm licence warnings appear for configured high utilisation, full, overbooked, expiring and expired packages in the licence register, **Requires attention** page and management reports.
12. As a corporate manager, confirm the Licence register, package details and employee licence block are available. As a department manager, confirm allocation names are limited to visible employees and direct access to an employee outside scope is denied.
13. Download the licence CSV, full XLSX and executive PDF. Confirm course, group, dates, purchased/used/free seats, utilisation, source, Bitrix references and status are present.
14. Open Public API diagnostics and confirm API release `1.9`, schema availability, package/allocation counts and duplicate-reference count.
15. Call `bridge::sync_course_license(...)` twice with the same company/deal/product row. Confirm one package is created and the second call updates it idempotently.
16. Call `bridge::assign_license_user(...)` twice for the same user/course/group. Confirm one active allocation. Pass an external reference belonging to another course/group and confirm the call is rejected.
17. Call `bridge::release_license_user(...)` before and after course start; verify the release rule and idempotent not-found/already-released responses.
18. Call `bridge::get_license_snapshot($bitrixcompanyid)` and compare packages, purchased/used/free totals, warnings and statuses with the UI.
19. Run the legacy `bridge::sync_training_assignment(...)` against a full package. Confirm company, employee and course mapping return `success=true` while nested `license.success=false` reports the capacity error and does not roll back the core sync.
20. Delete a test company and confirm its licence packages and allocations are deleted. Run Moodle Privacy API export/deletion tests for learner and `assignedby` user references.



## v0.13.0 — KBA Gamification integration

1. Upgrade from v0.12.0 with KBA Gamification 1.4.0 installed. Confirm Moodle completes the upgrade without schema errors.
2. Open **Version information** and confirm the Corporate Dashboard disk/database builds match and KBA Gamification reports a compatible API.
3. Open a company dashboard as a corporate manager. Confirm the **Gamification** navigation card appears and shows company XP.
4. Open **Gamification** and verify totals, level distribution and leaderboard use only employees from the selected company.
5. Assign a department manager and confirm department filtering never exposes employees outside their allowed departments.
6. Open **Employees** and confirm Level, KBA XP and Achievements columns appear when Gamification is available.
7. Open an employee card and confirm the employee's current level, XP and awarded achievement history are shown.
8. Disable **Show KBA Coins** and confirm no Coins values are visible in company, employee or leaderboard views. Enable it and confirm the values appear.
9. Disable **Show employee leaderboard** and confirm the leaderboard is replaced by an informational notice while aggregate gamification data remains visible.
10. Disable the KBA Gamification integration. Confirm all existing Corporate Dashboard pages continue to work and the Gamification page reports that integration is disabled.
11. Temporarily rename/remove `local/kbagamification` in a test environment. Confirm Corporate Dashboard continues to work without fatal errors and reports the optional plugin/API as unavailable.
12. Confirm existing courses, progress, licences, alerts, webinars, certificates, exports and public bridge API continue to work unchanged.

## v0.14.2.1 FIX regression
1. Confirm exactly one `{local_kbacorp_member}` row exists per `companyid + userid` after upgrade.
2. Confirm a dual-role user has `isemployee=1` and `ismanager=1`.
3. Repeat employee sync and verify it is idempotent.
4. Confirm Corporate Manager access remains after duplicate merge.
5. Open Webinars and confirm `apply_group_log_filter()` no longer raises an undefined-method exception.

## v0.17.1.2 — Business Role Management UI

1. Upgrade from build `2026090101` and confirm Moodle records savepoint `2026090102` without changing database tables.

2. Open **Site administration → Plugins → Local plugins → KBA Corporate Dashboard → Business roles**.
3. Select Company A, create a role with a unique `BMS-ROLE-*` code and confirm it is not visible in Company B's catalog.
4. Open the role profile and add several Moodle competencies with different target levels, priorities and required flags.
5. Edit one competency requirement and confirm no second row is created; explicitly remove one requirement and confirm only that row is deleted.
6. Assign two roles to one employee. Add a third role later and confirm the first two assignments remain active.
7. Assign the same role again and confirm no duplicate assignment is created.
8. Explicitly unassign one role and confirm other assignments remain active and the removed assignment remains in the database with `active=0` and `effectiveto` populated.
9. Open the employee's **My competencies** page with explicit `companyid`; confirm role-only competencies and the maximum target across multiple roles appear.
10. As a corporate manager, open a global role and confirm its definition is read-only but it can be assigned to an employee of the authorised company.
11. Change `companyid`, `roleid`, employee or assignment IDs in URLs to records from another company and confirm access is denied.
12. As a department-only manager, confirm company-wide role management is denied even if employee reporting remains available.

## v0.17.2 — Competency Audit

1. Upgrade from build `2026090102` and verify both audit tables are created at savepoint `2026090103`.
2. Create an employee audit and add assessments by at least two methods.
3. Verify all new assessments are pending and the current Moodle competency level is unchanged.
4. Confirm one assessment with a reviewer who has `moodle/competency:competencygrade`; verify Moodle creates the user competency/evidence.
5. Reject another assessment; verify the current level is unchanged.
6. Verify company A cannot open or change company B audits.
7. Verify a pending item appears as **Requires confirmation** in **My competencies**.
8. Delete the employee/company on a test database and verify audit child records do not block deletion.
9. Run Privacy export and delete for subject, assessor and reviewer.

## v0.17.3 — Automated Role-based Audit

1. Upgrade from build `2026090104`; verify savepoint `2026090200`, the new audit fields and `local_kbacorp_audit_ev`.
2. Give an employee two active business roles with overlapping competencies and verify one item per competency, maximum target level and strongest priority.
3. Create an audit with **Fill from business roles** enabled; verify no Learning Plan is required.
4. Verify an existing Moodle current level is immediately marked confirmed and recorded as collected evidence.
5. Verify core competency evidence and company-course competency results generate preliminary proposals only.
6. Verify a competency without grade-bearing evidence is **Needs assessment**, not level 0.
7. Enter an assessor evaluation for a missing item; verify the same item becomes pending and no duplicate is created.
8. Use **Confirm all proposals** and verify each level is written through the Moodle Competencies API.
9. Verify courses belonging only to another company are not collected.
10. Delete the employee/company and run Privacy export/delete; verify structured evidence is exported and removed before its parent item.

## v0.17.4 — Business Role Template Library

1. Upgrade from build `2026090200` and verify savepoint `2026090201` adds the three template-version fields.
2. Open **Business roles → Role template library** for a company.
3. As site administrator, create a global template and add several competency requirements.
4. As an authorised company manager, create a company role from the template.
5. Verify the name, code, description and full competency profile were copied and the source/applied version are shown.
6. Click the create action again and verify no duplicate company role is created.
7. Manually change one copied competency in the company role and add one company-only competency.
8. Change the global template, add another competency and verify the company role reports an available update.
9. Apply the update; verify template-controlled requirements update, the new requirement is added, and both manual/company-only requirements remain unchanged.
10. Verify employee assignments are not added, removed or replaced during template creation or update.
# Template version history (v0.17.9)

1. Upgrade from build `2026090207` and verify `local_kbacorp_rolever` and `local_kbacorp_rolevercomp`.
2. Confirm every existing global template receives a snapshot for its current version.
3. Edit a global template with a version comment and change its competency profile.
4. Open **Version history** and compare the latest version with its predecessor.
5. Confirm added, changed and removed requirements are reported correctly.
6. Open an outdated company role through **Preview update**.
7. Confirm manual overrides and company-only requirements are marked as preserved/retained.
8. Apply the update and verify employee assignments and confirmed competency levels are unchanged.
