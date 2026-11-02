# BMS 0.6.2 — Budgeting

Build `2026091522`.

## Delivered

- Versioned budgets for Administrative Scale goals/programs/projects and active canonical OFS org units.
- Mandatory exact published Financial Scenario revision; optional exact published Financial Responsibility Centre revision.
- Immutable budget-line snapshots with plan amount, spending limit, responsible Position, optional Indicator and source/basis.
- Append-only lifecycle: draft → submitted → approved/rejected; archived history is retained.
- Prior approved revision remains effective while a later amendment draft/rejection exists.
- Submission/approval blocks spending, investment, financing and reserve lines whose plan exceeds their limit.
- Aggregate management fact snapshots with as-of date and required provenance; plan/fact variance is derived on read.
- Company-scoped `/local/kbacompany/budgeting.php` inside the existing BMS App Shell.

## Not included

- accounting postings or general ledger;
- bank transaction storage;
- invoices / payables ledger;
- treasury/payment execution;
- Bitrix24 or ERP/Odoo writes;
- KBA Corporate learning changes.

The next roadmap release is v0.6.3 — Resource Gate.
