# Test Report — 0.22.2

Build: `2026090250`

Local gate covers XML parsing, localization parity and duplicates, PHP headers, conflict markers, version consistency and ZIP integrity. Live CRM acceptance requires the configured Moodle server and Bitrix24 webhook.

Acceptance:

1. Without a webhook the selector shows a controlled configuration error.
2. With CRM permission it lists the default and custom deal pipelines.
3. Changing pipeline and pressing “Load stages” refreshes the stage list.
4. Active Bitrix24 users appear in the responsible-person selector.
5. Saved category, stage and responsible ID survive reload.
6. A created deal appears in the selected pipeline and stage with the selected owner.
7. A Bitrix24 outage does not erase saved values or the Moodle program request.
