# Test Report — 0.22.2.1

Build: `2026090251`

Local gate covers XML, language parity, PHP headers, version consistency and ZIP integrity. Live acceptance requires the target Moodle/Bitrix24 connection.

Acceptance: category list loads; stage request sends `filter[ENTITY_ID]` and `order[SORT]`; user request sends `FILTER[ACTIVE]`; deal creation sends bracketed `fields[...]`; returned deal ID is persisted.
