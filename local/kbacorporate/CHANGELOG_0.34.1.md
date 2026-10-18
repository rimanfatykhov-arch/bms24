# Changelog v0.34.1

## Added

- `economic_dashboard.php` — панель KPI, фильтров, графиков и контекста аудита.
- `economic_dashboard_export.php` — CSV-экспорт текущей выборки панели.
- `classes/local/economic_dashboard.php` — read-only модель экономической панели.
- KPI-карточки: факт, предыдущий период, план, прогноз, источник и подтверждение.
- линейные графики факта и столбиковые графики `Факт / План / Прогноз`.
- ограничение до 8 одновременно выбранных показателей.
- защита от смешивания денежных значений разных валют.
- аудит-контекст с ближайшим предыдущим подтверждённым аудитом.
- `tests/economic_dashboard_test.php`.

## Changed

- `lib.php` — пункт **Экономика** ведёт на графическую панель.
- `economic_indicators.php` — добавлена кнопка перехода на панель.
- `implementation_audit.php` — переход к панели в контексте аудита.
- `implementation_audit_comparison.php` — переход к панели из сравнения.
- `styles.css` — адаптивные KPI-карточки, фильтры, графики и статусы.
- RU/EN language packs.
- build `2026090904` and release metadata.

## Database

No schema changes. Upgrade savepoint only.
