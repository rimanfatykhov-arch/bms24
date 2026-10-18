# Release Notes — v5.0.36.1.7

The v5.0.36.1.6 page crash exposed the actual structural defect:
older KBA Connect databases may have the catalog mapping tables but not the
`bitrix_section_id`/`iblockid` schema introduced by later code.

This release repairs the database schema before further category/product binding work.
After Moodle upgrade completes, run:
1. Catalog diagnostics;
2. Restore category mappings;
3. Normal catalog sync;
4. Diagnostics again.
