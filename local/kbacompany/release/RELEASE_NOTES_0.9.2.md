# local_kbacompany 0.9.2 — Bitrix24 Provider Extension
Build: 2026092604

## Added

- BMS Bitrix24 Provider API v1 (`local_kbacompany_bitrix_v1`), disabled by default / restricted users only.
- Provider readiness for scoped Bitrix24 environments and preserved legacy Company mapping mode.
- Company-scoped paged mapping projection.
- Reconciliation manifest over canonical BMS Company / Employees / Positions snapshots.
- Integration Center Bitrix24 Provider status card.

## Preserved

- Bitrix24 REST remains owned by KBA Connect / `local_bitrixsync`.
- Existing `local_kbacompany_syncout` legacy command outbox is unchanged.
- Provider-neutral event outbox remains the future-neutral event contract.
- Existing KBA Connect Company/Contact and course-enrolment flows are not rewritten.
- Company UUID remains the BMS security boundary.

## Not included

No direct Bitrix24 HTTP transport from BMS, no OAuth/webhook secret storage, no deals import, no automatic BMS fact write, no new DB tables/migrations.

## Acceptance status

ALPHA. Static/isolated QA only. Live Moodle 4.1.22, real KBA Connect, real Bitrix24 portal, OAuth/webhook REST, PHP 7.3 runtime, PHPUnit/Behat and production acceptance were not run here.
