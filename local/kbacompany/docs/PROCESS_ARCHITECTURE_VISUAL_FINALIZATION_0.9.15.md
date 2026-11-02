# Process Architecture Visual Finalization — 0.9.15

Presentation-only завершение визуального слоя Process Architecture и IDEF0.

- Process Architecture получает отдельный scrollable canvas, более явные L0/L1/L2 уровни и connector lines между родителем и дочерними узлами.
- Диагностические статусы, UUID/PRC identity, hierarchy, Process Model projection и все CRUD/mutation routes остаются без изменений.
- IDEF0 context получает visual frame и реальные CSS connector arrows вокруг центральной функции для I/C/O/M. Стрелки не являются execution semantics и ничего не сохраняют.
- На узких экранах диаграмма сохраняет геометрию через горизонтальную прокрутку вместо разрушения IDEF0 topology; Process Architecture переключается на вертикальный connector rail.
- Добавлен print-friendly fallback.

Новых таблиц, endpoint-ов, permissions или persistence нет.
