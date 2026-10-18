# AI DECISION LOG — KBA Connect v5.0.36.1.17

## Decision
Add a dedicated BMS Bridge diagnostic surface instead of extending Queue Manager into a BMS-specific debugger.

## Reasons
- Queue Manager is a generic operational queue and should remain generic.
- BMS troubleshooting needs cross-layer evidence: Bitrix Company flag, Company event, queue result, BMS mapping, BMS outbox, Corporate mapping and Contact resolution.
- The diagnostic must be safe on production and must not provide a hidden synchronization/write path.

## Boundary
Diagnostics reads Bitrix24 through KBA Connect, reads BMS through `local_kbacompany` integration API and reads KBA Connect-owned bridge/queue tables. It does not access `local_kbacorp_*` tables directly.

## Compatibility
No change to Deal-driven enrolment, Corporate learning membership semantics, ACL, schema or task schedule.
