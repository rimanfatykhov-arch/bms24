# local_kbacompany 0.7.0.17 — List / Kanban / Analytics View Foundation

Build: 2026092305

## Цель
Единый reusable-паттерн рабочих реестров BMS: **Список / Канбан / Аналитика**, без создания параллельных сущностей и без изменения canonical domain lifecycle.

## Реализовано
- Новый presentation-only `bms_collection_view_service`.
- Единый toolbar: поиск по текущему company-scoped набору + переключатель `Список / Канбан / Аналитика`.
- **Задачи**: list, kanban по lifecycle task status, analytics по открытым / на приёмке / просроченным / выполненным.
- **Разрывы Business Model**: list, kanban `Кандидат → Подтверждён → Архивирован`, analytics по статусам.
- **Стратегические цели ССП**: list, kanban по перспективам ССП, analytics по health и показателям.
- **Программы и Проекты Административной шкалы**: list, kanban по lifecycle, analytics по статусам и stale records.
- Responsive CSS для toolbar, kanban и metric cards.

## Архитектурные границы
- Domain services остаются единственным источником данных и lifecycle.
- Новый service не выполняет SQL и не рассчитывает permissions.
- Company isolation не меняется: фильтрация поиска применяется только к уже company-scoped результатам domain services.
- Theme не получает business routes или field schema.
- Schema / ACL / services / privacy не менялись.

## Не входит
- drag-and-drop смена статусов;
- отдельное хранение dashboard/kanban state;
- BI/pivot engine;
- массовые действия.
