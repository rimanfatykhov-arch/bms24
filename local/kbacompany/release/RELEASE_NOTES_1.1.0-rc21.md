# local_kbacompany 1.1.0-rc21 — BMS UI Freeze

Baseline: 1.1.0-rc20 build2026101341. Build: 2026101342.

UI stability patch for Quality Center: accessible active tabs, heading, responsive table and empty state. No schema, ACL, services, Moodle routing, external API, feature expansion or upgrade migrations. Critical interface acceptance matrix supplied separately. This RC is NOT approved for 1.1.0 FINAL; live testing is NOT RUN. RC20 nonconformity scope remains unresolved.

- UI hotfix: governance section now uses a local Documents / Policies / Deviations sub-navigation; App Shell top bar no longer duplicates a shifting section title, keeping BMS24 anchored in the left sidebar.

- UI hotfix 2: removed the narrower width constraint from Managed Documents and unified Documents / Policies / Deviations to the same full-width governance container so the header and actions stay aligned across the section.

- build2026101345: App Shell compatibility geometry for topbar and left sidebar brand. The common stylesheet is explicitly requested from the shared page shell and standalone workspace/architecture routes. Theme source and business logic unchanged. Live visual acceptance required.

- build2026101346: BMS24 logo in sidebar links to current company BMS workspace in all three App Shell entry points; no DB/schema/permissions changes. Live acceptance NOT RUN.

- build2026101347: Corrected SQL newlines in Odoo and Bitrix24 environment listing. The previous single-quoted \n sequences reached MySQL as literal backslashes. Company scope/ACL and storage unchanged. Live acceptance NOT RUN.
