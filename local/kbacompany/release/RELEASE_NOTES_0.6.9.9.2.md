# BMS 0.6.9.9.2 — Сотрудники: BMS CRUD

Build: `2026092202`

Раздел «Сотрудники» перестроен по `BMS-UX-CRUD-001`:

- ListView больше не содержит inline create form;
- добавлен отдельный CreateView;
- DetailView получил BMS Record Header, field groups, actions, membership history и collapsed technical details;
- EditView разделен на Личные данные / Контакты / Организация, при этом Position/Org Unit остаются read-only и управляются Staffing Placement;
- список поддерживает Company-scoped server-side поиск canonical memberships и фильтр статуса;
- внешние IDs и platform/integration diagnostics убраны из рабочего списка;
- диагностика и reconcile вынесены в отдельный route;
- destructive action в русском UI называется «Исключить из компании» и остается soft membership close.

Ownership boundaries не изменены: Moodle User = person identity; BMS membership = company relation; Position Assignment = organizational placement; KBA Connect = external synchronization; KBA Corporate = learning domain.

Schema-neutral. `install.xml`, `access.php`, `services.php` и privacy data model не изменяются. Upgrade содержит только savepoint `2026092202`.
