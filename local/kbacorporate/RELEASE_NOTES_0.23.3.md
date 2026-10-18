# KBA Corporate Dashboard 0.23.3

Build: `2026090257`

This release turns the multi-company “BMS implementation” page into an administrator worklist. Client cards now display the latest audit date and BMS Index, selected package, commercial stage, assigned curator, latest activity, and audit/program counters.

Administrators can sort by latest activity, company name or implementation state and select 12, 24 or 48 companies per page. Context-aware actions open the workspace, create a baseline audit, offer a package, or manage the existing implementation program. The program editor now supports assignment of an authorized implementation curator.

CSV export includes the complete filtered result rather than only the current page. All queries and exported rows remain restricted to companies visible to the current user.

Existing audits, BMS Index history, requests, programs, packages and Bitrix24 settings are preserved. Upgrade over v0.23.2 without uninstalling the plugin. No schema migration is required.
