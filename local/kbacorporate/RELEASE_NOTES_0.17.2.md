# KBA Corporate Dashboard 0.17.2 — Competency Audit

Release date: 2026-09-01  
Build: `2026090103`  
Minimum Moodle: `4.0` (`2022041900`)  
Public API: `1.15` (unchanged)

## Outcome

The plugin now supports a controlled employee competency audit:

1. A corporate manager creates an audit for one employee in one explicit company.
2. The assessor records a competency, method, proposed level, comment and optional evidence URL.
3. Every new assessment is preliminary and does not change the employee's current competency level.
4. An authorised reviewer explicitly confirms or rejects the assessment.
5. Confirmation calls Moodle core `\core_competency\api::grade_competency()` and creates the authoritative user competency/evidence in Moodle.

No Learning Plan is required. Competencies and scales come from the Moodle competency framework; Learning Plans remain an optional learning source.

## Security and data boundaries

- Audit lists, creation and review require `local/kbacorporate:managecompetencyaudits`.
- Every operation requires full access to the selected company; department-only managers cannot cross that boundary.
- Confirmation additionally requires Moodle core capability `moodle/competency:competencygrade` in the employee context.
- Site administrators can confirm by default. A custom reviewer role must receive the Moodle core capability separately.
- Company and employee deletion remove audit children before their parent records.
- Privacy API exports subject/assessor/reviewer audit data, deletes subject audits and anonymises actor references where appropriate.

## My Competencies integration

- A pending audit appears as a preliminary assessment and `Requires confirmation`.
- Rejected assessments never change the current level.
- A confirmed assessment is read back from Moodle as the current level.
- Business-role targets, course recommendations and the gap calculation remain unchanged.

## Database upgrade

Upgrade directly from installed build `2026090102`; do not uninstall the plugin. The upgrade adds:

- `local_kbacorp_audit`
- `local_kbacorp_audit_item`

The migration is additive and ends at savepoint `2026090103`.

## Acceptance path

1. Install the full ZIP over the current plugin.
2. Complete the Moodle database upgrade and purge caches.
3. Open **Site administration → Plugins → Local plugins → KBA Corporate Dashboard → Competency audits**.
4. Create an audit, add two preliminary assessments and verify the employee's current level has not changed.
5. Confirm one assessment as administrator and verify the current Moodle level changes.
6. Reject the second assessment and verify it does not change the current level.
7. Open **My competencies** and verify the confirmed level, audit evidence and gap.

## Release gate

Local structural checks are recorded in `TEST_REPORT_0.17.2.md`. PHP lint, Moodle PHPUnit and browser acceptance remain mandatory on the target Moodle runtime before declaring production validation complete.
