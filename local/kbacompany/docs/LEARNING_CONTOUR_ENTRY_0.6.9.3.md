# BMS 0.6.9.3 — Learning Contour Entry

Purpose: add one company-scoped BMS entry into development and learning before Competency Architecture starts.

## Ownership
- BMS owns navigation and authorised Company/User context only.
- KBA Corporate remains source of corporate course catalogue, licences/seats and corporate learning analytics.
- Moodle Core remains source of actual enrolment and course completion.
- Course completion does not mean competency attainment.
- Learning catalogue/licence/analytics data is read only through the KBA Corporate Public Bridge. Legacy Company → Bitrix identity fallback uses the existing `legacy_company_adapter`; no Corporate learning tables are queried from the learning service.

## UX
- New App Shell group: **People & development / Люди и развитие**.
- Routes: **Employees** (manager-visible) and **Development & learning** (all authorised company users).
- Workspace `Learning` card opens the same company-scoped learning route.
- Manager mode shows: active company employees, employees training, employees with completions, purchased/allocated/free seats, learning warnings, count-level seat/enrolment drift, company-course summary and employee learning status.
- Corporate drill-downs are capability-aware: Company courses, Progress, Licences and Corporate dashboard are shown only when the current user has the corresponding KBA Corporate capability; Corporate pages still re-check their own company ACL.
- Employee mode is **My development / Моё развитие** and shows in-progress, not-started and completed company-course states plus a `Continue learning` action when an active company course exists.
- Moodle courses outside the Corporate company catalogue are shown separately and never counted as company learning.

## Reconciliation boundary
The BMS page exposes a count-level drift signal by comparing Corporate licence allocations with active Moodle enrolment counts for the company-scoped BMS employee set. This is a warning only. Detailed user-level allocation ↔ enrolment reconciliation stays in KBA Corporate and never mutates enrolments automatically.

## Safety
- Schema-neutral.
- Company UUID remains the server-side security boundary.
- Site administrators can inspect a Company without having their own Company membership, using the existing explicit admin bypass.
- No direct Corporate learning-table reads/writes from `learning_contour_service`; the existing legacy adapter is used only for legacy provider-identity resolution.
- No automatic enrolment, licence allocation, competency logic, assessment, recommendation or Development Plan engine.
- Missing Corporate/Bitrix mapping degrades to an explicit unavailable state instead of fabricating data.
