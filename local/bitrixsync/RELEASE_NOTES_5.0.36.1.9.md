# Release Notes — v5.0.36.1.9

Production diagnostics confirmed the old category mapping table still uses:
`sectionid`, `parentcategoryid`, `lastsync`.

Current catalog code uses:
`bitrix_section_id`, `bitrix_parent_section_id`, `last_sync`.

This release performs the compatibility migration. It does not delete legacy data.
After Moodle finishes the database upgrade:
1. open Catalog;
2. run DB diagnostics;
3. restore category mappings;
4. run normal Catalog Sync;
5. re-run binding diagnostics.
