# KBA Connect v5.0.36.1.11 — Legacy Mapping Dual-Write Fix

- Fixed new category mapping inserts on historical production schema.
- Root cause: legacy `sectionid`, `parentcategoryid`, and `lastsync` columns are NOT NULL and have no defaults.
- `category_mapper` now writes canonical and legacy fields simultaneously when legacy columns exist.
- Existing mapping rows remain backward compatible.
- No legacy fields are deleted.
