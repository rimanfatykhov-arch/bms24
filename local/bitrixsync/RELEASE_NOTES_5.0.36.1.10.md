# Release Notes — v5.0.36.1.10

Use this release when Moodle's ordinary upgrade path leaves the historical six-column
catalog mapping schema unchanged.

Run sequence:
1. `Обновить структуру таблицы mapping`
2. inspect before/after fields
3. `DB диагностика category mapping`
4. `Восстановить связи категорий`
5. normal Catalog Sync
6. Catalog Binding Diagnostics

No legacy column is deleted by this release.
