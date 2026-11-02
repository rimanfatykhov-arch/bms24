# BMS Architecture Delta — v0.5.16

## Boundary

`local_kbacompany` becomes the canonical owner of BMS Company and employee membership while retaining the established company UUIDs used by OFS, positions, indicators, Battle Plans and Tasks.

The integration boundary is deliberately asymmetric:

`Bitrix24 ↔ KBA Connect ↔ local_kbacompany`

and separately:

`Bitrix24 Deal → KBA Connect → Moodle enrolment / local_kbacorporate learning context`.

BMS contains no Bitrix REST credential or transport code.

## New canonical storage

- `local_kbacompany_company` — BMS company lifecycle/profile.
- `local_kbacompany_member` — historical Moodle User ↔ BMS Company relationship.
- `local_kbacompany_extmap` — provider-neutral external identity map.
- `local_kbacompany_syncout` — durable outbound integration commands.

Existing pre-0.5.16 Corporate-backed companies are compatibility sources, not destructively migrated. Adoption pins the old BMS UUID through the existing identity registry.

## Identity

Company identity: BMS UUID + provider mapping to Bitrix Company ID.

Person identity: Moodle User ID.

Employee identity: Moodle User + BMS Company membership.

External Contact identity: provider mapping from Moodle User ID to Bitrix Contact ID.

Names and email addresses are not permanent integration keys.

## Access compatibility

Existing `local_kbacorporate` access-role projection is retained for legacy companies so staged adoption does not remove existing BMS access. New Corporate Learning role payloads do not silently grant BMS management roles.

Archived BMS companies are excluded from operational lists but remain resolvable for authorised historical links.

## Learning boundary

`local_kbacorporate` remains installed and owns Corporate Learning structures. BMS does not copy licences, seats, progress, grades, BBB or certificate facts. Course enrolment remains Deal-driven.

## Outbound reliability

Manual BMS employee creation succeeds locally first and writes a durable command. External failure does not roll back the Moodle User or BMS membership. A consumer may complete `employee_upsert` only after Contact identity and Corporate membership are both reconciled.
