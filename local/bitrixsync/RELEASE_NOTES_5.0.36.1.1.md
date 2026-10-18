# Release Notes — v5.0.36.1.1

Production symptom:
- existing Moodle categories failed with `Required fields: iblockId`;
- courses/products continued to synchronize.

Root cause:
`moodle_catalog_client::update_section()` omitted the current catalog iblock identifier
from `catalog.section.update`.

Fix:
`fields.iblockId = resolved catalog iblock ID`.
