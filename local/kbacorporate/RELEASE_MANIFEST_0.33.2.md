# Release Manifest v0.33.2

- Component: `local_kbacorporate`
- Release: `0.33.2 — Интеграция действий внедрения с Канбаном`
- Build: `2026090812`
- Status: BETA / pilot
- Minimum Moodle: 4.0
- Upgrade source: `0.33.1 / 2026090811`

## Artifacts

- `KBA_Corporate_Dashboard_v0.33.2_Implementation_Kanban_Integration_full.zip`
- `KBA_Corporate_Dashboard_v0.33.2_Implementation_Kanban_Integration_full.zip.sha256`
- `RELEASE_NOTES_0.33.2.md`
- `CHANGELOG_0.33.2.md`
- `TEST_REPORT_0.33.2.md`
- `AI_DECISION_LOG_0.33.2.md`
- `RELEASE_MANIFEST_0.33.2.md`

## New source files

- `classes/local/implementation_kanban.php`
- `implementation_board_update.php`
- `tests/implementation_kanban_test.php`

## Schema delta

Table `local_kbacorp_impl_tasklog`:

- add `eventtype CHAR(30) NOT NULL DEFAULT 'status'`;
- add nullable `detailsjson TEXT`.

No table or user data is removed.
