# Сотрудники — BMS CRUD UX — 0.6.9.9.2

Build: `2026092202`

## Цель

Перевести рабочий раздел «Сотрудники» на утвержденный `BMS-UX-CRUD-001` без создания нового Employee/Person engine и без изменения ownership данных.

## Canonical model

Сотрудник по-прежнему является каноническим Moodle User, связанным с BMS Company через `local_kbacompany_member`. Организационная должность принадлежит существующему BMS Position / Position Assignment. Bitrix24 Contact остается внешним mapping через KBA Connect. KBA Corporate владеет learning membership/licences/progress и не переносится в Employee CRUD.

## ListView

- форма создания удалена из списка;
- отдельная primary action «Создать сотрудника»;
- поиск по имени, фамилии, email и телефону для canonical memberships выполняется server-side в Company scope;
- фильтр: Действующие / Архивные / Все;
- основные колонки: Сотрудник, Организация, Контакты, Статус, Синхронизация, Изменено;
- Bitrix Contact ID и Moodle User ID удалены из основного списка;
- compatibility projection помечается отдельным read-only badge;
- техническая диагностика вынесена на отдельный экран.

## CreateView

Отдельный `/employee_create.php`:

- Личные данные;
- Контакты;
- после Save → DetailView созданного/привязанного сотрудника;
- должность не редактируется здесь и назначается через штатное размещение BMS.

## DetailView

Record Header:

- фото;
- имя сотрудника;
- статус связи с компанией;
- текущая должность/подразделение;
- primary action «Редактировать»;
- secondary menu «Действия».

Field groups / subpanels:

- Основная информация;
- Организация;
- Связь с компанией;
- Синхронизация;
- История связи с компанией;
- Дополнительно — collapsed technical IDs и source metadata.

## EditView

Schema-driven presentation через `employee_ui_service`:

- Личные данные;
- Контакты;
- Организация отображается read-only для контекста;
- изменение Position/Org Unit выполняется только через canonical Staffing Placement;
- Save → DetailView.

## Destructive action

«Удалить» в русском UI заменено на доменно точное «Исключить из компании». Действие по-прежнему является soft membership close: Moodle User, Bitrix Contact и исторические данные физически не удаляются. Mutation выполняется только через отдельную confirmation page + POST + sesskey.

## Diagnostics

`employee_diagnostics.php` отделяет техническую эксплуатацию от рабочего списка:

- Company external mapping;
- manual reconciliation queue;
- integration consumer/outbox summary;
- platform diagnostics в collapsed Advanced block.

## Boundaries

- schema-neutral;
- access.php/services.php/privacy provider unchanged;
- no new Person/Employee registry;
- no learning enrolment side effects;
- no automatic Position creation from CRM text;
- Company isolation remains server-side;
- KBA Theme remains presentation/App Shell only.
