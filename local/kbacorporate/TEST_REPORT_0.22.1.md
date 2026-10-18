# Test Report — 0.22.1

Build: `2026090249`

Local gate covers XML parsing, RU/EN parity, duplicate language keys, PHP headers, conflict markers and ZIP integrity. PHP lint, Moodle upgrade and live Bitrix24 calls require the target server.

Acceptance:

1. Upgrade seeds Start, Support and Transformation once.
2. Clean install seeds the same three packages.
3. Administrator can add/edit/deactivate a package without changing old program records.
4. Company request form lists only active packages.
5. Request is saved even when Bitrix24 is unavailable.
6. With valid webhook, pipeline and stage, CRM returns a deal ID stored in the program.
7. Existing deal ID prevents duplicate creation.
8. Corporate users never see the webhook.
