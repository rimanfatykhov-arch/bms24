# BMS 0.6.9.9.3 — Финансовая модель: BMS CRUD

Build: `2026092203`

Раздел «Финансовая модель» перестроен по `BMS-UX-CRUD-001` без создания второго финансового движка.

## Интерфейс

- `financial_model.php` стал рабочим пространством с вкладками **Обзор / Финансовые сценарии / Центры финансовой ответственности**.
- Удалены длинные inline-формы «Новый финансовый сценарий» и «Новый центр финансовой ответственности».
- Добавлены отдельные Create/Edit/Detail views для сценариев и ЦФО.
- После сохранения пользователь возвращается в DetailView созданной/изменённой записи.
- ListView поддерживает Company-scoped server-side поиск и фильтры по статусу и типу.
- DetailView использует Record Header, primary Edit, secondary Actions, semantic field groups, revision history и collapsed technical details.
- БДР / БДДС / прогнозный баланс, бюджетирование и ресурсный шлюз остаются отдельными существующими связанными контурами.

## Stable Code

- новые финансовые сценарии получают `FIN-xxxx` server-side;
- новые ЦФО получают `CFO-xxxx` server-side;
- коды не вводятся и не редактируются пользователем;
- существующие legacy-коды сохраняются при редактировании и новых редакциях;
- архивированные/исторические коды не переиспользуются;
- canonical identity по-прежнему UUID.

## Ownership boundaries

- Financial Scenario остаётся versioned management scenario;
- Strategy/Business Model links продолжают pin-ить опубликованные revisions;
- ЦФО повторно использует Company / Org Unit / Position / Product и BMS Indicator;
- БДР/БДДС/прогнозный баланс остаются `financial_statement_service`;
- бюджеты остаются `budgeting_service`;
- ресурсная проверка остаётся `resource_gate_service`;
- бухгалтерские/банковские факты и ERP ledger не переносятся в `local_kbacompany`.

## Upgrade

Schema-neutral. `install.xml`, `access.php`, `services.php` и privacy data model не изменяются. Upgrade содержит только savepoint `2026092203`.
