# KBA Connect v5.0.36.1.21 — BMS Employee Sync Stabilization

Build: `2026083631`

This schema-neutral hotfix closes two production findings from BMS v0.5.16 validation:

1. A BMS-created employee can remain deferred when the historical BMS Company has no direct Bitrix mapping even though KBA Corporate already knows the same Bitrix Company.
2. A Company Contact can be visible in `crm.contact.list` but disappear from the BMS sync result when `crm.contact.get` returns an empty profile.

The bridge now recovers Corporate Company identity through the documented Public API, persists that mapping locally, provides a safe list-profile hydration fallback, and reports Moodle-user resolution per Contact. Deal-driven course enrolment is unchanged.
