# Changelog v0.34.0

## Added

- `implementation_audit_comparison.php` — read-only сравнение двух подтверждённых аудитов.
- `classes/local/implementation_audit_comparison.php` — нормализованный агрегатор критериев, разделов, рисков, действий, доказательств и экономических значений.
- фильтры по изменению, риску, разделу и поисковой строке;
- CSV-экспорт сравнения;
- ссылки из списка аудитов, карточки аудита, разделов BMS и компактного меню;
- `tests/implementation_audit_comparison_test.php`.

## Changed

- `lib.php` — пункт «Сравнение аудитов» в рабочем меню;
- `implementation_audits.php` — кнопка и действия сравнения;
- `implementation_audit.php` — переход из подтверждённого аудита;
- `implementation_section_progress.php` — переход к сравнению текущего аудита;
- `styles.css` — адаптивное оформление сравнения;
- RU/EN language packs;
- build `2026090901` and release metadata.

## Database

No schema changes. Upgrade savepoint only.
