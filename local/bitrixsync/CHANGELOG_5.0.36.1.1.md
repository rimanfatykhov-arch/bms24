# KBA Connect v5.0.36.1.1 — Catalog Section Iblock Fix

- Fixed Moodle category -> Bitrix24 catalog section updates.
- Modern `catalog.section.update` now receives the required `fields.iblockId`.
- Prevents Bitrix24 error `Required fields: iblockId` when updating existing catalog categories.
- Course/product synchronization logic is unchanged.
- Corporate Role Manager work is unchanged.
