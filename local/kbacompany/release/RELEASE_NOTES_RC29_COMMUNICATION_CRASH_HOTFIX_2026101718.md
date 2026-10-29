# BMS24 RC29 build2026101718 — Communication Center runtime crash hotfix

- Fixed fatal `Call to undefined function local_kbacompany\local\string_exists()` in `communication_center_ui_service::tr()`, triggered by the search placeholder in build1717.
- Replace raw `string_exists()` with Moodle-supported `get_string_manager()->string_exists()`. The existing RU/EN search-label fallback remains; no other global `string_exists()` calls remain in the plugin.
- Extended RC29 release-blocking source regression gate with two targeted checks so this error cannot be repackaged unnoticed.
- No DDL, no ACL or communication write/read service changes; canonical existing message behavior preserved.
- Recommended acceptance: install, purge Moodle caches, load Communication Center and check search, composer, send message, mentions and files.
- Static QA only; live Moodle / DB functional testing NOT RUN.
