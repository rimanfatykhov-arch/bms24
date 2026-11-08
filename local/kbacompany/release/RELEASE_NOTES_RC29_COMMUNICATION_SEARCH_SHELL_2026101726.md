# RC29 build2026101726 — Communication Center shell and live search

- Added per-page BMS24 shell collapse/restore control for the KBA and plugin-owned shells. The sidebar stays intact on other pages and no Moodle core is edited.
- Retained the independently scrollable conversation list; made its scrollbar discoverable.
- Live search filters already displayed conversations immediately and asynchronously refreshes the authorized result list after 350 ms. Keeps current conversation/composer in place, does not create records, and does not open or mark any searched conversation read. Original GET form remains a fallback.
- Moodle 4.0 AMD module includes source/build; no DDL, canonical write-path or RBAC changes.
- Live Moodle acceptance NOT RUN.
