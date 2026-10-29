# BMS 0.6.9.11 — Философия и Рынок: Domain Foundation

Build `2026092205`.

## Scope

- canonical versioned Company Philosophy (`PHL-*`);
- versioned Decision Principles (`PRN-*`);
- enriched canonical Market Assessment (`MKT-*`) using the existing Market root;
- versioned Market Evidence (`EVD-*`) with `Факт / Требуются данные / Гипотеза / Открытый вопрос` semantics;
- BMS CRUD pages for Philosophy and Market;
- published Philosophy/Market revision pinning in Business Model and Strategy;
- first BMS Assistant pilot via optional `local_kbaai` bridge;
- provider-neutral read-only Market Data Tool Gateway contract.

## Boundaries

- AI never writes canonical BMS data directly.
- AI output is proposal-only and is copied to a form only by explicit user action.
- No external company-data endpoint is hard-coded without a verified provider contract and credentials.
- No duplicate Finance, Indicator, Project, CRM or ERP engines are introduced.
- Existing Market/Segment/Offering records remain readable; legacy Market codes are preserved and backfilled into `stablecode`.
