# KBA Corporate Dashboard 0.23.1

Build: `2026090254`

This release promotes “BMS implementation” to the permanent Moodle navigation beside “My competencies” and the corporate dashboard. The link retains a validated company context and is hidden when a multi-company user must first choose a company.

The implementation workspace now shows the active editable tariff catalogue directly after its summary. A customer can select BMS Start, BMS Support, BMS Transformation or another configured package without opening a separate plan page. The request remains linked to the latest baseline audit; when a request already exists, the workspace shows its selected package and status instead of offering a duplicate order.

When no baseline audit exists, the tariff area explains that Point A is required and opens audit creation. Existing audits, requests, programs, tariffs and Bitrix24 settings are preserved.

Upgrade over v0.23.0 without uninstalling the plugin. No schema migration is required.
