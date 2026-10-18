# TEST REPORT — v5.0.36.1.9

Static:
- full PHP syntax check;
- install.xml version updated;
- Moodle ZIP integrity/root checked.

Production acceptance:
1. Complete Moodle DB upgrade.
2. DB diagnostics should show canonical fields:
   bitrix_section_id, bitrix_parent_section_id, iblockid, status, last_sync, error, timecreated, timemodified.
3. Existing legacy section IDs must be copied forward.
4. Restore mappings for categories 8/9/10.
5. Run Catalog Sync.
6. Final diagnostic target:
   Expected Section = Saved Section = Live Section > 0.
