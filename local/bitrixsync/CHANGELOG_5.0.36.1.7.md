# KBA Connect v5.0.36.1.7 — Catalog Mapping Schema Repair

- Fixed production `Ошибка чтения из базы данных` after catalog sync.
- Root cause: historical `local_bitrixsync_catmap` installations could exist without fields later expected by the Moodle Master Catalog.
- Upgrade now idempotently repairs category and product mapping schemas.
- Attempts to recover old section IDs from legacy columns `bitrixid`, `sectionid`, or `bitrix_sectionid`.
- Fresh `install.xml` now includes both catalog mapping tables.
- Catalog summary counter is schema-tolerant and cannot take down the page.
