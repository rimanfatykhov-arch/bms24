# BMS v0.6.1 — BDR / BDDS / Forecast Balance

Build `2026091521`.

## Purpose

v0.6.1 adds the first canonical planned financial statements on top of the v0.6.0 Financial Model foundation. It does not create an accounting ledger and does not import actual ERP or bank facts.

Canonical chain:

`Published Financial Scenario revision -> planned Financial Statement revision`

`Optional published Financial Responsibility Centre revision -> planned Financial Statement revision`

The statement types are:

- `bdr` — planned economic result (revenue, variable expenses, contribution margin, fixed operating expenses, operating profit, other income/expenses, taxes, net profit);
- `bdds` — four-week planned cash movement with opening cash, receipts, payments and automatically rolled closing cash;
- `balance` — forecast assets, liabilities and equity with mandatory balance equality before publication.

## Data ownership

`local_kbacompany` owns the plan/forecast definitions and revisions. It does not own or mutate:

- bank transaction facts;
- accounting postings;
- payment execution;
- statutory accounting;
- ERP ledgers;
- Bitrix24 CRM transport;
- KBA Corporate learning data.

Actual operational/financial facts may be integrated later through an explicit provider contract; they are not silently copied into the v0.6.1 tables.

## Versioning

`FinancialStatement` is append-only and company-scoped:

`draft -> published -> archived`

Editing an active statement creates a new draft revision. Publishing clones the draft to an immutable published revision. Archiving appends an archived revision; older history remains intact.

Every statement pins:

- exact published Financial Scenario revision;
- optional exact published Financial Responsibility Centre revision;
- period and currency.

The statement period must fit inside the scenario horizon, and its currency must equal the scenario currency.

## BDR formulas

- Contribution margin = Revenue − Variable expenses.
- Contribution margin % = Contribution margin / Revenue × 100.
- Operating profit = Contribution margin − Fixed operating expenses.
- Profit before tax = Operating profit + Other income − Other expenses.
- Net profit = Profit before tax − Taxes as economic expense.

Derived rows are calculated on read and are not stored as editable facts.

## BDDS formulas

For each of four buckets:

`Closing cash = Opening cash + Total receipts − Total payments`

Only bucket 1 has manually entered opening cash. Each subsequent bucket automatically uses the previous bucket's closing cash as its opening cash. Negative forecast cash is allowed as a diagnostic signal and is surfaced to the manager; it is not hidden or automatically repaired by moving payments.

## Forecast balance validation

`Total assets = Cash + AR + Inventory + Other current assets + Fixed assets + Other assets`

`Total liabilities and equity = AP + Debt + Other liabilities + Equity`

A forecast balance cannot be published while the absolute balance gap is greater than `0.01` in the statement currency.

## Storage

Two additive tables:

- `local_kbacompany_finstmt` — versioned statement header and pinned context;
- `local_kbacompany_finline` — immutable input cells for a specific statement revision.

Calculated rows are not persisted.

## UI

`financial_model.php` remains the Scenario / responsibility-centre foundation page and links to `financial_statements.php`. Both use the existing BMS App Shell through `bms_page_shell_service`; KBA Theme remains presentation-only.

## Deferred scope

v0.6.1 intentionally does not implement:

- payment calendar transaction registry;
- budget approval workflow and spending limits;
- plan/fact using imported actual ERP/accounting data;
- treasury execution;
- financial consolidation across legal entities.

The next roadmap release is v0.6.2 Budgeting.
