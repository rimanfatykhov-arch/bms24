# KBA Connect v5.0.31

## CRM Field Engine Rebase

Root-cause fixes:
- removed PHP 8-only `str_ends_with()` from CRM field discovery;
- removed PHP 8-only `str_starts_with()` from message templates;
- restored v0.4.6 CRM userfield transport: JSON body + `.json`;
- restored v0.4.6 pagination semantics: continue only when Bitrix returns `next`;
- removed erroneous manual `start += 50` fallback;
- preserved actual legacy field types;
- added legacy enumeration encoding for `KBA_MOODLE_ENROL_STATUS`;
- CRM writeback now uses old enumeration IDs correctly;
- clears only stale failed/processing CRM-field queue jobs on upgrade;
- added exact CRM field transport test CLI.

Preserved:
- existing v0.4.6 fieldmap;
- existing Bitrix fields;
- production data;
- current v5 execution engine.
