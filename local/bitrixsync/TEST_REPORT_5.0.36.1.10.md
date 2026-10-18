# TEST REPORT — v5.0.36.1.10

Static:
- PHP syntax checked for full plugin.
- ZIP root and integrity verified.
- version/install/upgrade verified.

Production acceptance:
- catmap before may contain id/categoryid/sectionid/parentcategoryid/name/lastsync;
- after repair it must additionally contain:
  bitrix_section_id, bitrix_parent_section_id, iblockid, status,
  last_sync, error, timecreated, timemodified;
- legacy section IDs must be copied forward;
- categories 8/9/10 must then be eligible for mapping repair.
