# KBA Connect v5.0.36.1.2 — Catalog Product Binding Fix

- Added `fields.iblockId` to `catalog.product.update`.
- Normalized `iblockSectionId` to integer.
- Added post-update product fetch and section verification when Bitrix returns section data.
- Prevents courses/products from remaining outside their Moodle-mapped Bitrix section while categories themselves sync correctly.
