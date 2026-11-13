# Values / Culture / Motivation — 0.7.9

## Purpose
BMS 0.7.9 adds a company-scoped people-management contour for values, observable culture indicators and employee motivation factors.

## Model
- `cultitem` is a company-defined management reference: value, culture indicator or motivation factor.
- `cultscore` is an employee-specific snapshot against one active item.
- Scores use a simple 1–5 management scale and are not competencies or psychological diagnostics.
- No automatic staffing, dismissal, compensation or competency decision is triggered by a culture score.

## UX
The new `culture.php` workspace follows the current BMS24 interface standard:
- Record Header and local People tabs;
- KPI cards;
- company-scoped employee selector;
- compact status table;
- inline controlled scoring;
- explicit boundary notice.

## Security / privacy
All reads and writes are company-scoped. Write operations require `local/kbacompany:managepeople`. Privacy metadata, export and deletion handling are extended for the new tables.
