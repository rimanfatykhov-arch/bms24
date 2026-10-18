# Release Notes — v5.0.36.1.11

Production diagnostics confirmed categories 8/9/10 had no mapping rows.
The canonical schema exists, but the historical legacy columns are still NOT NULL.
New rows therefore failed because current code supplied only canonical fields.

This release uses compatibility dual-write:
- bitrix_section_id + sectionid
- bitrix_parent_section_id + parentcategoryid
- last_sync + lastsync

After installation:
1. Restore category mappings.
2. Run normal catalog sync.
3. Run binding diagnostics.
