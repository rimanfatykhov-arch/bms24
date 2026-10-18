# AI Decision Log — v5.0.36.1.1

The observed catalog failure is an API payload regression, not evidence that the catalog
scheduled task is missing. Existing categories invoke `catalog.section.update`, whose
modern Bitrix24 contract requires `fields.iblockId`.
