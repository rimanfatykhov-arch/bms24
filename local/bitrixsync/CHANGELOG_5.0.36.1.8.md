# KBA Connect v5.0.36.1.8 — Category Mapping DB Write Diagnostics

- Added deep DB diagnostics for `local_bitrixsync_catmap`.
- Displays real production columns, defaults, NOT NULL flags and indexes.
- Displays current rows for Moodle Category IDs 8, 9 and 10.
- Detects duplicate category IDs and duplicate non-zero Bitrix section IDs.
- Adds rollback-only `update_record` probe so write-shape problems can be observed without persisting changes.
- Displays Moodle exception errorcode/debuginfo when the DB layer rejects the probe.
