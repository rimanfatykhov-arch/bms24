# DOC-W2 — рабочее пространство «Канцелярия» (BMS-12)

Статус: **кандидат в PR, не принят, не установлен**. Исполнитель: Claude. Дата: 24.11.2026.
Основание: MASTER ROADMAP 3.0 (дополнение MR3-A01), BMS-ARC-DOC-001 v0.1 «Канцелярия» (проект к утверждению),
поручение владельца 24.11.2026.

## 1. Что сделано

Отдельная точка входа `local/kbacompany/office.php` в BMS App Shell (группа меню «Управление», перед «Документами»).
Это **только обзор**: страница ничего не создаёт и не изменяет и не владеет данными. Все числа и списки берутся из
существующих сервисов-владельцев с правами текущего пользователя.

Схема, версия плагина, capabilities, публичные API, жизненные циклы и формулы **не менялись**.
Механизм включения — существующий паттерн настроек сайта (`settings.php`, как `dailyreports_enabled`):
`local_kbacompany/office_workspace_enabled`, **по умолчанию выключено**. Пока выключено, нет ни страницы, ни пункта меню.

## 2. Аудит перед изменением (24.11.2026)

| Пункт | Результат |
|---|---|
| `main` | `62fdc4934b8b8a87fb8c7f21a52c56b64229e836` (#165) |
| Плагин | `local_kbacompany` 1.1.0-rc29, `version = 2026112301` (проверено в `version.php` текущего `main`), `requires = 2022041900` |
| Версия/savepoint | не меняются: нет DDL, нет upgrade-шагов |
| Флаги | отдельного сервиса feature flags нет; используется существующий паттерн `admin_setting_configcheckbox` в `settings.php` |
| Дубликаты задач | открытых issues/PR про Канцелярию, Финансы, DOC-W*, FIN-W* нет |
| Открытые PR с пересечениями | см. §8 |
| Google Docs | MASTER ROADMAP 3.0 — прочитан (разделы MR3-A01, BMS-12/BMS-05); «Канцелярия» — прочитана по ссылке из Roadmap (ссылка в поручении `…JyJytaube…`/`…bot…` вернула «Entity not found», рабочая — `…bobyyJytaube…`); «Финансы» — прочитан. **Master Context, Canonical Ownership Matrix и CRUD Standard в этой сессии не открывались — не утверждаю, что они изучены** |

Инвентарь основы (существующее, переиспользовано без изменений):

| Объект | Где | Права |
|---|---|---|
| Управляемые документы (ISO), редакции, согласование, ознакомление, файлы | `documents.php`, `document_service`, таблицы `doc`, `docreview`, `docack` | `document_service::browse/readable_record`: менеджер видит последние редакции; сотрудник — действующие и назначенные ему на согласование |
| Политики и управленческие директивы | `policies.php`, `policy_service`, таблица `policy` | `policy_service::browse` (видимость по `cascade_service::can_manage`) |
| Встречи и решения | `meetings.php`, `meeting_service` | только встречи, где пользователь участник |
| Поручения (задачи из решений встреч) | `tasks.php`, `task_service` (`sourcekind = decision`) | `task_service::list_page`, область «мои» (фактический исполнитель с учётом замещения) |
| Стабильные коды | `stable_code_service` | не используется в обзоре (регистрационных номеров пока нет) |

## 3. Карта разделов

| Раздел (BMS-ARC-DOC-001) | Состояние в DOC-W2 | Канонический маршрут |
|---|---|---|
| Обзор | активен | `office.php?uuid=` |
| Документы | активен | `documents.php?uuid=` |
| Согласование | активен | `documents.php?uuid=&status=review` |
| Контроль исполнения | активен | `tasks.php?uuid=&scope=my&sourcekind=decision&signal=open` |
| Протоколы → «Встречи и решения» | активен | `meetings.php?uuid=` |
| Политики и директивы (основа для приказов) | активен | `policies.php?uuid=` |
| Входящие | **планируется** (DOC-W3) — текст без ссылки | — |
| Исходящие | **планируется** — текст без ссылки | — |
| Внутренние документы | **планируется** — текст без ссылки | — |
| Приказы и распоряжения | **планируется** (DOC-W5) — текст без ссылки | — |
| Договоры | **планируется** (DOC-W6) — текст без ссылки | — |
| Архив | **планируется** (DOC-W6) — текст без ссылки | — |

ISO-документы не выдаются за корреспонденцию: на странице это прямо сказано, «Входящие/Исходящие» не активны.
Канцелярия не становится вторым владельцем документов, политик, протоколов, задач, сообщений и файлов.

## 4. Карта сервисов (главная страница)

| Блок | Источник | Совпадает со списком |
|---|---|---|
| На согласовании | `document_service::browse(['status'=>'review'])['total']` | `documents.php?status=review` |
| Требуют ознакомления | свои `docack.status = required` по **действующей** редакции этой компании (то же правило, что `can_acknowledge` в карточке документа) | карточки документов |
| На исполнении | `task_service::list_page(scope=my, sourcekind=decision, signal=open)['total']` | ссылка с теми же фильтрами |
| Просрочено | то же, `signal=overdue` | ссылка с теми же фильтрами |
| Документы | `document_service::browse([])['total']` | `documents.php` |
| Директивы | `policy_service::browse(['kind'=>'directive'])['total']` | `policies.php?kind=directive` |
| Требуют внимания | только ненулевые: просрочено, на согласовании, ознакомление, предложенные решения встреч (`meeting_service::context()['counts']['open_decisions']`) | ссылки на владельцев |
| Последние документы | первые 5 строк `document_service::browse([])` | `documents.php` |
| Быстрые действия | «Новый документ» — только если `document_service::can_manage`; остальные — ссылки на владельцев | — |

Сервисы: `classes/local/office_workspace_service.php` (модель, без записи), `classes/local/office_workspace_ui_service.php`
(только отображение, всё экранируется). Пустые состояния — у каждого блока свой текст; нули не выдумываются и не
подменяют отсутствие данных.

## 5. Права и изоляция

* `office.php`: `require_login` → `viewowncompany` → флаг → `company_context_service::context` (членство, отзыв).
* Каждый счётчик — `COUNT` того же запроса, что и у страницы-владельца, для того же пользователя; данные другой
  компании не читаются (company UUID в SQL-условии каждого запроса); фильтрации в PHP после выборки нет.
* `manageorganization` не расширялся; новых capabilities нет. Менеджерский вид определяется существующим
  `document_service::can_manage` (= `permission_service::can_manage_management`).
* Отзыв членства (`membership_access_service::change`) закрывает страницу на следующем запросе.
* Счётчики не попадают в уведомления, поиск, экспорт или URL — их там нет.

## 6. Тесты

| Тест | Что проверяет | Статус |
|---|---|---|
| `tests/office_workspace_service_test.php` (PHPUnit, 5 тестов) | флаг выкл → нет маршрута и пункта меню; счётчики = итогам сервисов-владельцев; две компании; черновики и данные B не видны сотруднику A; менеджерский вид; отзыв членства; пустая компания; «планируемые» разделы — не ссылки | **CI** (Moodle 4.1.22, MariaDB/Percona) — см. handoff |
| `tools/ci/browser/office_workspace.py` + `contract.office_workspace_checks` (7 проверок) | реальный браузер: флаг выкл; 4 случая owned/legacy × 1440 EN / 375 RU; счётчики = сервису; меню и текущий пункт; переходы по разделам; менеджер; EB не видит A; отзыв в открытой сессии; горизонтальная прокрутка; ошибки браузера/5xx; скриншоты `logs/screens/office-*.png` | **CI BROWSER** — см. handoff |
| `tools/ci/browser/test_contract.py` (+3 теста, 30 мутаций) | контракт ловит регрессии и неполный прогон | локально PASS (обычный и `-O`) |
| `scripts/qa_local.py`, `check_reconcile_1879.py`, `check_pr53_pr56_integration.py`, сохранность языковых файлов | статические гейты | локально PASS (PHP 7.4.33) |

Не выполнялось (NOT RUN): установка на TEST/production, ручная приёмка владельцем, независимая проверка Codex,
проверка на реальных данных компаний, cron/гонки (страница только читает).

## 7. Release notes (кандидат)

* Новое: «Канцелярия» — обзор документов на согласовании, требующих ознакомления, поручений на исполнении и
  просроченных, директив, последних документов, блок «Требуют внимания» и быстрые действия. RU/EN, desktop/mobile,
  owned и legacy оболочки.
* Включение: Администрирование → Плагины → Локальные плагины → BMS24 UI → «Включить рабочее пространство
  „Канцелярия“». По умолчанию выключено.
* Без изменений схемы, версии, прав, уведомлений, cron.

## 8. Пересечения и порядок слияния

| Общий файл | Изменение DOC-W2 | Пересечение |
|---|---|---|
| `classes/local/bms_ui_service.php` | маршрут `office` после `documents`, иконка в строке `policies`, порядок группы «Управление» | FIN-W2 меняет другие строки (маршрут после `financialmodel`, группа «Архитектура»); текстового конфликта не ожидается |
| `settings.php` | флаг после `dailyreports_cc_max` | FIN-W2 добавляет свой флаг там же → **конфликт при слиянии второго PR**: оставить оба блока |
| `lang/{en,ru}` + evidence + `check_pr53_pr56_integration.py` + тесты сохранности | новый блок после блока R3 «Обсуждение отчёта» (#169; `main` влит в ветку 25.11.2026, хэши пересчитаны) | FIN-W2 и открытые стеки отчётов (#168…#177, #173) и MAX (#178/#179) тоже дописывают в конец → второй по очереди **перебазируется** и пересчитывает хэши (before = after предыдущего) |
| `tools/ci/browser/contract.py`, `browser_acceptance.py`, `test_contract.py` | новые проверки в конце | FIN-W2 добавляет свои там же → конфликт «оба блока» |

Рекомендуемый порядок: DOC-W2 → FIN-W2 (FIN-W2 перебазируется). Слияние — только по решению владельца.

## 9. Roadmap checkpoint

BMS-12 «Канцелярия»: DOC-W0 аудит — выполнен в этой задаче; DOC-W1 контракт — проект v0.1 (не утверждён);
**DOC-W2 workspace — реализован в PR, ждёт CI/ревью/приёмки**; DOC-W3+ (реестр входящих `offrec/offrev/offseries`,
нумерация, резолюции, маршруты, договоры, дела) — не начинались, требуют отдельного gate на DDL.

## 10. Риски и откат

* Риск: пользователь воспримет обзор как реестр корреспонденции → снят текстом и неактивными разделами.
* Риск: стоимость запросов — 7 запросов с `COUNT` + встречи пользователя; без сканов чужих компаний.
* Откат: выключить флаг (данные не создаются) или откатить коммит; миграций нет.

## 11. Changed paths

```
local/kbacompany/office.php                                   (новый)
local/kbacompany/classes/local/office_workspace_service.php   (новый)
local/kbacompany/classes/local/office_workspace_ui_service.php (новый)
local/kbacompany/bms_ui/office_workspace.css                  (новый)
local/kbacompany/tests/office_workspace_service_test.php      (новый)
local/kbacompany/classes/local/bms_ui_service.php             (маршрут, иконка, порядок меню)
local/kbacompany/settings.php                                 (флаг, по умолчанию 0)
local/kbacompany/lang/en/local_kbacompany.php                 (блок officews_*, append-only)
local/kbacompany/lang/ru/local_kbacompany.php                 (блок officews_*, append-only)
docs/reconcile/office-workspace-doc-w2-source-preservation.json (новый)
tools/check_pr53_pr56_integration.py                          (digest-строки)
tools/tests/test_pr102_source_preservation.py                 (цепочка evidence)
tools/tests/test_dailyreport_source_preservation.py           (маркер блока)
tools/ci/browser/office_workspace.py                          (новый)
tools/ci/browser/ci_office_workspace.php                      (новый, CI-only)
tools/ci/browser/contract.py                                  (office_workspace_checks)
tools/ci/browser/test_contract.py                             (мутационные тесты)
tools/ci/browser_acceptance.py                                (запуск сценария)
docs/office/DOC_W2_OFFICE_WORKSPACE.md                        (этот документ)
```
