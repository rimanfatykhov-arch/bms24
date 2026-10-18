## v5.0.36.1.23 — BMS Employee Position Projection

Bitrix Contact standard `POST` is now forwarded through the BMS bridge as `position`. With BMS `0.6.9.2+`, that value becomes the Moodle/KBA Corporate display projection while canonical BMS Position remains untouched. Empty `POST` never clears profile data.

## v5.0.36.1.22 — BMS Employee Phone Mapping Fix

Outbound BMS employee email and phone fields are now strictly separated.

## 5.0.36.1.21 — BMS Employee Sync Stabilization

- Recovers Bitrix Company ↔ KBA Corporate Company identity from the documented Corporate Public API when the local KBA Connect bridge row is missing.
- Company Contact hydration no longer silently skips contacts: `crm.contact.list` carries a safe fallback profile when `crm.contact.get` is empty/unavailable.
- BMS sync result exposes per-contact hydration source and Moodle-user resolution (`mapped`, `email_match`, `created`, `not_found`, etc.).
- Successful Corporate employee sync persists the company mapping for future outbound BMS commands.
- Deal → Product → Course enrolment remains unchanged.
- Schema-neutral build `2026083631`.

## 5.0.36.1.19 — Cached BMS Field Selector Fix

- BMS client Company field selector now loads verified boolean fields from the local CRM Field Engine cache before attempting live Bitrix discovery.
- Prevents an empty selector when the settings-page REST refresh is unavailable while CRM Fields already reports the field as Ready.
- No schema, ACL, cron or synchronization-contract changes.

# KBA Connect v5.0.14-beta.68

Final Execution Report Storage.

Added:
- final execution report;
- result persistence preparation;
- history/timeline/metrics update boundary;
- RC preparation state.

Release Candidate preparation:
- installation testing;
- upgrade testing;
- permissions review;
- controlled run review.
