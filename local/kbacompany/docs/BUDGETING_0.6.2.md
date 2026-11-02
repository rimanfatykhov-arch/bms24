# BMS v0.6.2 — Budgeting

Build: `2026091522`

## Purpose

Add a company-scoped budgeting layer on top of Financial Scenario / Responsibility Centre and the Administrative Scale without creating an accounting ledger.

## Canonical objects

- `Budget` — versioned header with target, planning horizon, Scenario/Centre pins and approval lifecycle.
- `BudgetLine` — immutable line snapshot per budget revision with plan amount, spend limit, responsible Position, optional Indicator and source.
- `BudgetFactSnapshot` — append-only aggregate management fact for plan/fact with as-of date and source provenance.

## Targets

- goal, program and project point to exact published `local_kbacompany_adminitem` revisions;
- org-unit budgets point to an active canonical `local_kbacompany_orgnode`;
- no parallel goal/project/org tree is introduced.

## Lifecycle

`draft -> submitted -> approved | rejected -> amendment draft ... -> archived`

Approved revisions are immutable. A prior approved revision remains effective while an amendment is being drafted or has been rejected.

## Limits and approval

Expense, investment, financing and reserve lines receive an explicit spend limit. When omitted, the plan itself becomes the initial limit. Submission/approval is blocked if plan exceeds limit. Approval/rejection is explicit and append-only; rejection requires a decision note.

## Plan / fact

Plan is the approved BudgetLine amount. Fact is an aggregate management snapshot for the same approved line and date. Variance is derived on read (`actual - plan`, plus percentage when plan != 0). Fact requires source provenance and does not replace ERP/accounting source data.

## Ownership boundary

`local_kbacompany` owns budgeting governance and aggregate management snapshots. KBA Theme is presentation-only. KBA Connect and KBA Corporate are unchanged. No accounting postings, bank transaction storage, invoice registry, treasury execution or ERP write is introduced.
