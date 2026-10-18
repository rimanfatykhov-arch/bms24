# Changelog v0.33.3.1

## Added

- Безопасная миграция пользовательских описаний KBA-шаблонов и KBA-планов Moodle с `Odoo` на `ERP`.
- No-schema upgrade savepoint `2026090814`.

## Changed

- Пользовательские подписи API обмена переведены на нейтральную терминологию ERP.
- Источник экономических данных с техническим значением `odoo` теперь отображается как `ERP`.
- Описания интеграции учебных планов и корпоративных метаданных KBA используют `ERP`.
- Пояснения параметра провайдера во внешнем API больше не привязаны к конкретному продукту.

## Preserved

- Provider token `odoo`.
- API function names and capability identifiers.
- Existing external mappings and integration data.
- Database schema.
