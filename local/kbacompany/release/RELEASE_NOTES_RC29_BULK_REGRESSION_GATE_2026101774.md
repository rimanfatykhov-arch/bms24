# RC29 build2026101774 — Bulk operations regression gate

## Scope
This is a stabilization release following the resolved XMLDB blocker. It does not add or modify database tables and does not change UI/CRUD behavior.

## Release gate
- New `cli/check_decision_bulk_contract.py` checks the server-side bulk operation workflow, authorization markers, limit of 20 selected records, CSRF verification, validation of dates and owners, per-record handling, revision verification, audit write stage, and SQL/XMLDB audit table naming consistency.
- `cli/build_release.py` invokes the new gate before artifact creation and blocks packaging on failure.
- Moodle upgrade checkpoint `2026101774` (no schema change).

## Limits
Source-level checks cannot prove runtime permissions, transactions, or live Moodle behavior. Before production rollout, manually test preview, confirmation, change-status/owner/deadline, failed-record audit, and access roles on a staging site.

## Live acceptance
NOT RUN.
