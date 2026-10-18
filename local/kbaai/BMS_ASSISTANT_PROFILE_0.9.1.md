# KBA AI Assessor 0.9.1 — BMS Assistant Profile Foundation

Build `2026092201`.

Adds a provider-neutral `bms_assistant_service` and structured BMS proposal contract on top of the existing `local_kbaai` provider abstraction.

Supported operations:
- explain;
- interview;
- draft;
- review;
- evidence_check.

The BMS Assistant is proposal-only. It does not write canonical BMS records and does not bypass BMS permissions or validation. Yandex provider v080 supports structured BMS proposals; the mock provider supports development testing.

No database schema changes are introduced.
