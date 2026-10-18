# KBA Corporate ERP API — Org Structure & Role Sync v0.35.2

**Build:** `2026091007`  
**Contract major:** `1`  
**Contract release:** `1.2.0`  
**Public bridge:** `1.19`

## Новые entity types

- `department` — company department metadata and soft lifecycle;
- `department_membership` — immutable employee placement with `employee` or `manager` role;
- `business_role` — company business-role metadata without competency-profile mutation.

## Ownership

- ERP обновляет и деактивирует только созданные им departments и business roles.
- Совпавшие ручные записи могут быть связаны, но не перезаписываются.
- ERP удаляет только созданное им department membership; ручное назначение сохраняется.
- Деактивация business role не удаляет назначения и требования компетенций.
- Глобальная библиотека шаблонов бизнес-ролей не изменяется ERP.

## Snapshot

`pull_company_snapshot` аддитивно возвращает `organisation.departments` и
`organisation.business_roles`. Персональные данные сотрудников не добавляются.

## Методологические гарантии

Оргструктура и роль не подтверждают уровень компетенции. ERP sync не создаёт оценки,
не повышает зрелость BMS, не меняет Learning Plans и не превращает отсутствие оценки в 0.
