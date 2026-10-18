# v0.29.6.3 — BMS Workspace Menu Optimization

- Removed the standalone BMS tools card from workspace navigation.
- Merged Framework Manager, BMS tool library and JSON exchange under `Методология BMS`.
- Rebalanced desktop and responsive menu layout without changing routes or data.
- No database schema changes.

# v0.14.0 — Gamification Engagement UX

- Visual top learner cards in Corporate Dashboard.
- Latest team achievements feed.
- Latest achievement badge on employee page.
- Russian user-facing terminology uses KBA Баллы.
- Integration still reads Gamification data only through its public internal API.
- No database schema changes.

# KBA Corporate Dashboard changes

## 0.13.0 — KBA Gamification Integration

- Added optional integration with `local_kbagamification` through `\local_kbagamification\api`; no direct Gamification table reads.
- Added a company/department-scoped **Gamification** workspace with total XP, average XP, achievements and level distribution.
- Added configurable employee leaderboard with department-aware access isolation.
- Added XP, current level and achievement count to the corporate employee list when the integration is available.
- Added the employee achievement history to the employee card.
- Added privacy-oriented setting to keep KBA Coins hidden from corporate managers by default.
- Added `local/kbacorporate:viewgamification` capability for corporate and department manager roles.
- Added graceful fallback when Gamification is missing, disabled or its API fails.
- Extended Version information with KBA Gamification build/API diagnostics.
- No Corporate Dashboard database schema changes are required.

## 0.12.0 — Corporate Licences, Seats & Contract API

- Added independent corporate licence packages with Moodle course/group, purchased-seat quantity, validity dates, source, Bitrix24 Deal ID and Product Row ID.
- Added explicit employee seat allocations with assigned, started, completed and released lifecycle states; a seat may be released only before training starts.
- Added company and course capacity KPIs: purchased, used, free, utilisation, unlimited packages, full/overbooked packages and expiry warnings.
- Added administrator pages for creating/editing licence packages, allocating seats, releasing unused seats and retaining contract history through soft deactivation.
- Added the corporate **Licences and seats** register, employee licence block, dashboard cards, course metrics, CSV registry, XLSX sheet and executive PDF/report-centre sections.
- Existing v0.11 company-course mappings are migrated to unlimited `legacy` packages; currently enrolled corporate employees are backfilled as assigned seats so upgrades preserve existing behaviour.
- A real manual/Bitrix package takes precedence over legacy unlimited compatibility rows. Full, expired or inactive commercial packages can no longer be bypassed by an automatically created unlimited package.
- Added warnings for high utilisation, full, overbooked, expiring and expired packages to the licence register, control centre and management reports.
- Added `local/kbacorporate:viewlicenses` and `local/kbacorporate:managelicenses` capabilities plus Privacy API metadata/export/deletion support for seat allocations.
- Public API release raised to `1.9` with `sync_course_license()`, `get_license()`, `assign_license_user()`, `release_license_user()` and `get_license_snapshot()`.
- `sync_training_assignment()` remains backwards compatible: company/member/course synchronisation succeeds even when licence allocation fails; licence status/error is returned separately for BMS Connect diagnostics.
- Added schema/count/duplicate external-reference checks to Public API diagnostics. No direct SQL access from BMS Connect is required or supported.

## 0.11.0 — Learning Control & Notifications

- Added a dedicated **Requires attention** control centre with period, department, employee, severity and signal-type filters.
- Added calculated signals for missing enrolment, course not started, inactivity, low progress, low grade, learning-time plan, webinar attendance and missing certificate after completion.
- Added high/medium/low severity, per-employee risk score and a dedicated control-signals CSV export.
- Added global administrator thresholds and a minimum severity for notification digests.
- Added per-company notification enablement and daily/weekly frequency settings.
- Added Moodle notification provider `attentiondigest`, manual administrator send action and a daily scheduled task at 07:15.
- Corporate managers receive all company signals; department managers receive only employees in their assigned departments.
- Added company fields `alertsenabled`, `alertfrequency` and `lastalertsent`; no persistent alerts table is used.
- Corrected redundant XMLDB indexes on fresh installation so foreign-key fields are not declared twice.
- Public API release raised to `1.8` with `bridge::get_attention_snapshot()` and attention diagnostics.

## 0.8.2 — Course Links

- Course names are now clickable throughout the corporate dashboard.
- Links open the standard Moodle course page (`/course/view.php?id=...`).
- Added to dashboard overview, progress, employee card, company courses, training time, webinars, certificates and course administration.
- No database schema changes.


## 0.8.1 — Admin Version Information

- Added an administrator-only **Version information** page.
- Shows release, component, build on disk and the plugin version stored by Moodle.
- Adds an automatic synchronisation warning when disk and DB versions differ.
- Links version diagnostics with the existing Public API diagnostics page.
- No database schema changes.

## 0.8.0 — Certificates & Document Registry

- Added optional read-only `mod_customcert` adapter and a dedicated corporate **Certificates** workspace.
- Added company certificate registry with employee/course/status filters, issue date, issue code and duplicate-issue visibility.
- Added secure per-certificate PDF download proxy: access is re-checked against Corporate Dashboard company membership, active company-course mapping and optional group scope before PDF generation.
- PDF generation supports the Moodle 4.0-era `mod_customcert\template::generate_pdf()` API and the newer service-based Custom Certificate API when available.
- The registry never issues certificates; it only reports existing `customcert_issues` records. "Not issued" therefore does not imply eligibility/completion.
- Added certificate summary KPIs to the company dashboard and employee card.
- Added dedicated certificate-registry CSV export.
- Public API release raised to `1.5` with `bridge::get_certificate_registry_snapshot()` and Custom Certificate availability diagnostics.
- Fixed an extra duplicate course cell in the employee BBB table introduced in v0.7.0.
- No local database schema changes.

## 0.7.0 — BigBlueButton Analytics 2.0

- Added factual BBB engagement analytics from Summary data: chat, talks, raised hands, poll votes and emoji reactions.
- Added first-join analysis from Moodle BigBlueButton Join logs and configurable late tolerance.
- Added presence coverage percentage when the BBB activity open/close window is a plausible single-webinar duration.
- Long multi-day access windows are explicitly excluded from presence/lateness calculations to avoid misleading metrics.
- Added optional logout/early-leave analytics when a Logout record is available.
- Added webinar analytics KPIs, richer employee webinar view and dedicated webinar CSV export.
- Extended the general corporate CSV with average webinar coverage, late count and engagement events.
- Public API release raised to `1.4` with `bridge::get_webinar_analytics_snapshot()`.
- API diagnostics now expose BigBlueButton Analytics availability.
- No database schema changes.

## 0.6.0 — Learning Time Analytics 2.0

- Added current week / current month / current quarter / custom period presets.
- Named periods end today so future working days do not reduce plan-completion KPIs.
- Added weekly plan-vs-actual dynamics with Moodle estimate, BBB actual time, variance and completion percentage.
- Added per-employee variance and explicit plan status (`Plan met` / `Below plan`).
- Added company KPI for employees below plan.
- Added course share of total learning time.
- Split learning-time CSV into employee, weekly, course and daily exports.
- Expanded custom period ceiling to 366 days while keeping bounded log queries.
- Public API release raised to `1.3` with read-only `bridge::get_training_time_snapshot()` for BMS/CRM reporting.
- No database schema changes.

## 0.5.0 — BMS Full Sync Contract

- Public API release raised to `1.2` while keeping major API v1 backwards compatible.
- Added `bridge::sync_training_assignment()` for one atomic/idempotent BMS company + employee + course/group synchronisation.
- Added `bridge::sync_company_courses()` for bounded company catalogue synchronisation with optional soft deactivation of missing mappings.
- Added `bridge::unassign_course()` for idempotent soft-disable/reactivation workflows.
- Added repository helpers for soft course deactivation, raw company course mappings and member counts.
- Company/course CRM snapshots now include employee and corporate-manager counts.
- API diagnostics now show active/inactive course mappings and a `writesafe` health signal.
- BMS still owns Moodle enrolment/group assignment; Corporate Dashboard stores the corporate company/member/course model only.
- No database schema changes.

## 0.4.5 — Public API v1.1 / Company Courses

- Unified the v0.4.4 Public API contract/diagnostics build with the parallel BMS company-course snapshot build.
- Public API release is now `1.1` while the major contract remains backwards-compatible API v1.
- Added `bridge::get_company_snapshot()` for one Bitrix24-linked company and its explicitly assigned corporate courses.
- Added `bridge::list_company_snapshots()` with a bounded local-company-ID cursor for scheduled CRM publishing.
- Added repository helpers for bounded company batches and company-course catalogue reads.
- Corporate course snapshots use `local_kbacorp_course` as the source of truth; employee enrolments are not used to infer the catalogue.
- Kept `manage.php`, `api.php`, schema diagnostics, duplicate Bitrix company checks and all API v1 write methods from v0.4.4.
- No database schema changes.


## 0.4.3
- Unified the two divergent 0.4.2 builds into one canonical release.
- Preserved the public `local_kbacorporate\local\bridge` API required by BMS Connect.
- Preserved repository helpers for idempotent company/member sync by Bitrix24 Company ID.
- Added the legacy `/local/kbacorporate/manage.php` compatibility endpoint, redirecting authorised administrators to `companies.php`.
- No database schema changes.

## 0.4.2
- Added public `local_kbacorporate\local\bridge` API for optional BMS Connect integration.
- Added idempotent company lookup/create/update by `bitrixcompanyid`.
- Added idempotent employee relation synchronisation using the existing `membertype=employee` model.
- Added a per-Bitrix-company Moodle lock to reduce duplicate creation during concurrent Deal syncs.
- Added read-only bridge status counts for BMS Connect diagnostics.
- No database schema changes. Existing dashboard, reports, BigBlueButton and learning-time logic are unchanged.

## 0.4.1
- Fixed Moodle upgrade failure when `viewtrainingtime` was assigned before the new capability was registered.
- `viewtrainingtime` now clones permissions from `viewprogress`, preserving access for existing corporate-manager roles.
- Upgrade is safe to rerun after a partially failed 0.4.0 attempt; schema fields are guarded by `field_exists()`.

# KBA Corporate Dashboard — changes

## 0.3.0
- Added a read-only BigBlueButton attendance adapter for Moodle 4.0 core `mod_bigbluebuttonbn`.
- Added a Webinars section with filters by course, employee, and attendance status.
- Reads participant duration from BigBlueButton `Summary` logs (`meta.data.duration`) and shows time present.
- Supports BBB minimum-attendance completion thresholds and marks whether the threshold was met.
- Added webinar attendance KPI to the corporate dashboard and employee detail page.
- Added webinar attendance fields to CSV export.
- Respects company course-to-group mappings for group-specific BigBlueButton meetings.
- No local database schema changes are required.

## 0.2.0
- Added a corporate workspace landing page with four read-only sections: Employees, Learning progress, Company courses, and Report.
- Added a dedicated Employees page that also shows employees before courses are assigned.
- Added a detailed Progress page with course and status filters.
- Added a Company courses overview with per-course KPIs.
- Added administrator setup actions for empty companies while keeping corporate-manager access read-only.
- Improved empty states, responsive layout, KPI cards, status styling, and mobile presentation.
- Preserved strict company-level server-side access checks on every new page.

## 0.1.3
- Added role-aware permanent navbar shortcut for corporate managers.

## 0.1.2
- Fixed ZIP packaging with empty directories.

## 0.1.1
- Added database migration from `bitrix_company_id` to `bitrixcompanyid`.

## 0.1.0
- Initial MVP.

## 0.4.0
- Added **Learning time / Учебное время** corporate report based on the historical KBA weekly hours spreadsheet.
- Added a per-company learning plan: hours per employee per learning day and selectable weekdays.
- Added daily plan/fact table with cumulative plan and cumulative actual hours.
- Added employee summary: planned hours, estimated Moodle hours, BigBlueButton hours, total hours and plan completion.
- Added actual time summary by corporate course.
- Added current-week learning-time block to the employee card.
- Added separate CSV export for learning hours.
- BigBlueButton duration is read from Summary data; normal Moodle time is deliberately labelled as an estimate based on standard-log activity sessions.
- Added configurable inactivity threshold (default 30 min) and session-tail allowance (default 5 min).
- Added `local/kbacorporate:viewtrainingtime` capability.

## 0.4.4 — Public API Contract

- Formalised the optional integration bridge as Public API v1 (`API_VERSION = 1`, release `1.0`).
- Kept `bridge::sync_member()` and `bridge::status()` backwards-compatible for existing BMS Connect builds.
- Added canonical `sync_employee()`, `sync_company()`, `get_company_by_bitrix_id()`, `remove_employee()` and `assign_course()` methods.
- Added a machine-readable `bridge::contract()` method so BMS Connect can negotiate capabilities without inspecting internal files/tables.
- Added defensive schema diagnostics and duplicate Bitrix24 Company ID detection.
- Added an administrator page: `/local/kbacorporate/api.php`.
- Added repository helper for idempotent company/course/group mapping checks.
- No database schema changes in this release.

## 0.9.0 — Report Center
- Added a unified corporate Report Center with period selection.
- Added executive management PDF export using Moodle's PDF/TCPDF wrapper.
- Added multi-sheet XLSX export using MoodleExcelWorkbook/PhpSpreadsheet.
- Added executive attention/risk indicators and course portfolio summary.
- Added Public API v1.6 `get_management_report_snapshot()` for BMS/CRM integrations.
- No local database schema changes.

## 0.10.0 — Departments and Managers

- Added company organisational structure: Company → Department → Employees / Department Managers.
- Added read-only Department overview to the corporate dashboard.
- Added administrator pages to create departments and assign employees/managers.
- Added dedicated `KBA Department Manager` role with department-scoped access.
- Department managers can only see employees assigned to departments they manage.
- Corporate managers and site administrators retain company-wide visibility.
- Added department filters to dashboard, employees, progress, company courses, learning time, webinars, certificates and report centre.
- Scoped CSV/XLSX/PDF exports to the viewer's allowed departments.
- Added department column to employee lists and management XLSX/general CSV exports.
- Added Public API v1.7 `get_company_structure_snapshot()` and department diagnostics.
- Extended Privacy API and uninstall cleanup for department assignments/roles.

## 0.10.1 — Department XMLDB Fix

- Fixed the Moodle XMLDB coding error during the v0.10.0 upgrade: `company_fk(companyid)` no longer collides with a redundant `company_ix(companyid)` definition.
- Removed the same redundant one-field `userid_ix(userid)` definition from the new department-member table because `user_fk(userid)` already provides the foreign-key/index relationship.
- The upgrade remains restart-safe after a failed v0.10.0 attempt: no manual database cleanup is required.
- No additional business schema changes beyond the v0.10.0 department tables.

## 0.14.1 — Gamification UX Cache Hotfix
- Forces a Moodle plugin upgrade/cache refresh after the 0.14.0 engagement UI update.
- Adds a versioned engagement stylesheet so leader/latest-achievement cards render immediately.
- Adds safe fallbacks for the new engagement language strings when an old language cache is still active.
- No database schema changes.

## 0.14.2.1 FIX — Employee Sync & BBB Compatibility
- Unified company membership by `companyid + userid` with `isemployee` / `ismanager` role flags.
- Added idempotent duplicate migration and unique membership index.
- Preserved corporate-manager role assignment while merging duplicate rows.
- Added missing schema-aware BigBlueButton group log filter.
- Updated diagnostics and Version Information for production support.
