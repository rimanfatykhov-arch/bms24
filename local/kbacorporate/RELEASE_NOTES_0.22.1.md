# KBA Corporate Dashboard 0.22.1

Build: `2026090249`

The three initial BMS packages are now seeded as editable records instead of being hard-coded. Site administrators can add and edit packages, including price, currency, duration, description, features, order and availability. Existing programs continue to use the stable codes `start`, `support` and `transformation`.

Bitrix24 settings include an incoming CRM webhook, dedicated pipeline `CATEGORY_ID`, initial `STAGE_ID`, and an automatic-deal switch. A customer request is always committed in Moodle first. CRM failure is logged but does not lose the request. Administrators can manually retry deal creation.

Upgrade over v0.22.0 without uninstalling the plugin. Configure the webhook after installation; no secret is included in the ZIP.
