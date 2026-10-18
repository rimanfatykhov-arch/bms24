# KBA Corporate Dashboard 0.23.2

Build: `2026090256`

This release adds an administrator-focused client search and implementation-state filter to the multi-company “BMS implementation” entry page. Administrators can find a client by company name or isolate companies with no audit, a completed audit but no request, an existing request/program, or an active implementation program.

Each client card now displays a concise workflow status together with audit and request/program counts. Aggregate calculations remain tenant-safe and include only companies available to the current user.

Existing audits, BMS Index history, requests, programs, packages and Bitrix24 settings are preserved. Upgrade over v0.23.1.1 without uninstalling the plugin. No schema migration is required.
