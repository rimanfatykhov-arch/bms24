# local_kbacompany 1.1.0-rc11.1 — build 2026101228

## Hotfix

Исправлена ошибка чтения штатного расписания на MySQL 5.7:

`You have an error in your SQL syntax ... near 'lines, COALESCE(SUM(plannedfte)...'`

Причина: агрегатный алиас `lines` в SQL-запросе сводки штатных планов конфликтовал с синтаксисом MySQL 5.7.

Исправление:
- SQL alias: `lines` → `linecount`;
- внешний ключ view-model `lines` сохранён без изменений;
- схема БД, данные, permissions и privacy metadata не изменялись.
