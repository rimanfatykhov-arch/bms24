# KBA Connect v5.0.36.1.6 — Catalog Category Mapping Repair

- Added safe repair of Moodle category -> existing Bitrix section mapping.
- Repair order: XML_ID -> unique CODE -> unique exact NAME.
- Repair never creates or deletes Bitrix sections.
- Ambiguous matches are skipped.
- Repaired sections are normalized with the KBA Moodle XML_ID/CODE and hierarchy.
- Fixed `Связано разделов Bitrix24`: now counts only mappings with `bitrix_section_id > 0`.
- Diagnostics refresh automatically after repair.
