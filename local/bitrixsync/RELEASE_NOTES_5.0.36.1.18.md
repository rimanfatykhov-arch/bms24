# KBA Connect v5.0.36.1.18 — Explicit BMS Client Field Selector

Safety hotfix on top of v5.0.36.1.17.

## Delivered
- Adds an explicit Bitrix24 Company field selector to KBA Connect essential settings.
- The selector shows live single boolean Company userfields with their human label, FIELD_NAME and XML_ID.
- `Company → BMS` synchronization now uses only the administrator-selected exact FIELD_NAME.
- XML_ID, label and legacy-name fallback matching are no longer used for the BMS admission flag.
- If no field is selected, BMS Company synchronization is safely paused/deferred.
- If the saved field disappears or is no longer a single boolean field, synchronization is paused and diagnostics expose the error.
- BMS Bridge Diagnostics uses the same explicit selected field as the production bridge.

## Safety
- No schema changes.
- No access/permission changes.
- No scheduled-task changes.
- No Deal → Course enrolment changes.
- No direct KBA Corporate table writes.
