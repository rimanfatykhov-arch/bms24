# Integration Architecture

## Principle

BMS boundaries must stay provider-neutral.

## Bitrix24

Current CRM provider.

Ownership of Bitrix24 REST/webhook integration remains in KBA Connect / `local_bitrixsync`.

`local_kbacompany` may expose:
- readiness
- canonical snapshots
- provider mappings
- reconciliation manifests
- public/service API contracts

Avoid embedding Bitrix-specific REST code throughout BMS domain services.

## Odoo

Odoo is operational execution/actual ERP.

BMS owns management model and planning.
Odoo owns transactions/fact.

Integration should map canonical BMS UUIDs to Odoo provider IDs.

## Public API

Prefer bounded snapshots/contracts over direct database reads from external systems.

Public API should:
- enforce company scope
- expose canonical identities
- avoid leaking internal/legacy IDs unless contract explicitly requires them
- avoid private PII unless necessary and authorized

## Outbox/events

Prefer provider-neutral events/outbox + consumers.

Events should not accidentally leak:
- Moodle user IDs
- provider secrets
- emails/phones unless explicit contract requires them
- Bitrix IDs as canonical identifiers

## Failure handling

Integration failure must not corrupt canonical BMS state.
Use explicit sync/reconciliation status.
