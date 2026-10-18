# KBA Connect v5.0.36.1.9 — Legacy Catalog Mapping Schema Migration

- Migrates legacy `local_bitrixsync_catmap` schema forward without deleting old columns.
- Copies `sectionid` -> `bitrix_section_id`.
- Copies `parentcategoryid` -> `bitrix_parent_section_id`.
- Copies `lastsync` -> `last_sync`.
- Adds missing `iblockid`, `status`, `error`, `timecreated`, `timemodified`.
- Repairs `local_bitrixsync_productmap` with matching canonical fields.
- Adds canonical indexes where existing data permits.
- Stores migration timestamp in plugin config.
- Old legacy columns are intentionally retained for rollback safety.
