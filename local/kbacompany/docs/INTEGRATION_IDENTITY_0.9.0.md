# Integration Identity 0.9.0 — implementation boundary

## Scope
This is the first installable slice of roadmap 0.9.0 Integration Platform Foundation.
The implementation reuses `integration_service` and `local_kbacompany_extmap`.
The provider-neutral event outbox and legacy command consumer contracts are unchanged.
No credentials, endpoints, scheduling, provider transport or inbound actual writes are enabled.

## Identity and namespace
Scoped identity fields: companyuuid, mappinguuid, provider, environment, externalmodel,
entitykind, localref, externalid, namespacekey, externalkey, active, timecreated, timemodified.
Canonical localref is a UUID v4 for company, position or indicator.
Provider/environment/model is an external namespace; display names, email and phone are not identity.
Environment and model are restricted lowercase technical identifiers; external IDs accept
lowercase ASCII letters, digits and `_.:-`, maximum 100 characters, without URLs or credentials.
Input is rejected rather than silently lowercased. This narrow grammar is intentional in 0.9.0.
Environment is an operator-selected identifier, not a verified endpoint or proof of provider ownership.

namespacekey = SHA256(JSON([provider, environment, externalmodel])).
externalkey = SHA256(JSON([provider, environment, externalmodel, externalid])).
The global externalkey unique constraint rejects cross-company and cross-kind reuse without
fetching another company's mapping. Errors do not include its UUID, external ID or SQL.
Company-scoped queries precede all new domain reads. Lists use a bounded 50-row page and
an exact company-scoped count. Form options use up to 100 positions and 100 indicators;
manual UUID entry remains subject to the same scoped validation.

## Lifecycle and limits
Create: authenticated existing permission → scoped preview (zero domain writes) → explicit
checkbox → scoped validation again under company/provider/environment lock → insert + core event
inside a delegated transaction. Repeating the same active relation returns the existing mapping UUID.
A changed external ID or closed relation cannot silently overwrite/reactivate a mapping.
Close: explicit confirmation → scoped read and permission → same lock → active-child guard →
mark inactive + core event inside transaction. Repeating close creates no second close event.
The standard Moodle event log owns actor/time history; no parallel audit engine is introduced.
Business UUIDs, facts, tasks and learning objects are never changed by these methods.

Root company mapping model is `res.company` for Odoo and `crm.company` for Bitrix24.
These are BMS identity namespace labels in this slice, not a claimed remote API request contract.
A child needs an active company mapping in the same provider/environment. External-model names
for children are declared manually and will need adapter-specific validation before actual sync.
Future adapters MUST verify the connection, company binding, object/model and provenance,
apply their capability policy, and reject stale/closed/unverified relationships as appropriate.
This release does not assert that a manually declared mapping is safe for automatic imports.

## Legacy compatibility and upgrade
Legacy mappings keep namespacekey/companyuuid/environment/externalmodel blank. mappinguuid and
externalkey remain NULL. Existing legacy lookup methods explicitly select the blank namespace.
Current KBA Connect references and IDs are not migrated, copied or replaced.
Automatic adoption of a duplicate BMS company is blocked once that company has scoped mappings.
Upgrade 2026092412 adds six fields, then five replacement/auxiliary indexes, then drops two old
indexes. Each DDL operation is guarded. No data backfill or deletion is performed.
Tested baseline: 0.8.12 build 2026092411; live SQL upgrade and older upgrade paths remain NOT RUN.
Rollback after deployment requires restoring the matching DB/code backup, not only an older ZIP.

## Source contracts
Project authority: BMS-MASTER-001 v1.4.12 (read before development), roadmap 0.9.x.
Detailed source: BMS-ARC-INT-001 v0.1 (draft), Integration & External Identity Contract.
Local implementation contracts are in integration_service::scoped_mapping_contract().
Moodle primary references consulted: https://docs.moodle.org/dev/Lock_API ;
https://docs.moodle.org/dev/Events ; https://moodledev.io/docs/5.0/guides/upgrade .
These references are not evidence of a live Moodle 4.1.22 execution.
