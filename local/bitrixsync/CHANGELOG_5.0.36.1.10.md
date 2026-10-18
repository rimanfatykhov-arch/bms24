# KBA Connect v5.0.36.1.10 — Manual Legacy Catalog Schema Repair

- Added explicit administrator action `Обновить структуру таблицы mapping`.
- Additive migration for historical `local_bitrixsync_catmap`.
- Adds canonical fields required by current catalog code.
- Copies legacy `sectionid`, `parentcategoryid`, `lastsync` forward.
- Legacy fields are intentionally preserved.
- Repairs product mapping schema in the same safe pass.
- Automatically refreshes DB diagnostics after repair.
