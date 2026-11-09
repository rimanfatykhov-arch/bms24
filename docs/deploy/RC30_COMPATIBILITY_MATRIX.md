# RC30 — матрица совместимости и рисков

2026-11-09. **HOLD.** Это анализ кода/сохранённых evidence, не результат
текущей установки на edu.kzn.mba. База/артефакт зафиксированы в
[preflight](RC30_PRODUCTION_PREFLIGHT.md). Source candidate0906 не изменялся.

## Точный путь BMS1878 →0906

Git1878 `a87252b642e5796c6d84105dd448d51d9a5b5241` найден и проверен.
Его `local/kbacompany/db/install.xml` побайтно совпадает с PR62e7e9b1b.
**Это не доказательство, что Git1878 равен production source или actual DB.**
Полный production tree остаётся NOT VERIFIED. Schema metadata получены в live
снимке20:55:42Z; обнаружен дополнительный drift, см. [refresh](RC30_PRODUCTION_REFRESH.md).

| Участок | Фактическое поведение | Production acceptance |
|---|---|---|
| upgrade.php:6107+ checkpoint1879 и0301–0804 | Version-only сохранение порядка | NOT RUN от actual1878 |
| checkpoint2026110890 | Достижимый repair3полей | executionstatus отсутствует и в свежем снимке; repair не выполнялся |
| checkpoint2026110900 | Повтор guarded repair + ordered index | NOT RUN на actual production schema/volume |
| checkpoints0901–0906 | Code-only savepoints | Не разрешают заменять иной feature tree |
| install.xml | Новых таблиц относительно Git1878 нет | Actual metadata получены;142 BMS tables,13 дополнительных field/type drift за пределами repair |
| исторические repairs<2026092503/2505 | Недостижимы от1878 | Не исправят возможный production drift |

Источник: [upgrade.php](../../local/kbacompany/db/upgrade.php),
[upgradelib.php](../../local/kbacompany/db/upgradelib.php),
[acceptance harness](../../tools/tests/moodle_acceptance.php).
В полном upgrade.php0906 —480 монотонных savepoints; для1878→0906
выполняется только хвост после1878, а не все480 миграций.

`upgradelib.php:24–74` добавляет только отсутствующие поля:

- task.starttime / task.duetime: char5 XMLDB → varchar(5), NOT NULL, default empty.
- deviation.executionstatus: char16 → varchar(16), NOT NULL, default pending.
- Уже существующие совместимые значения не переписываются; несовместимые
  определения останавливают upgrade. Нельзя обходить guard ручным version reset.

`upgradelib.php:85–145` обеспечивает nonunique index `(companyuuid,status,duedate)`.
Исторический production index `(companyuuid,duedate,status)` не эквивалентен.
Старые индексы сохраняются; конфликт reserved name останавливает upgrade.
DDL на Percona может implicit commit и брать metadata locks: это не транзакционный
rollback. Повторный запуск проверяет существующие объекты, но не заменяет restore.
Перед реальной установкой измерить lock duration, table/index sizes, temp disk,
лаг и длительность DDL на точной изолированной копии; generic CI этим не является.

CI имеет fresh +1645,1879,0890,0900,0901,0804,PR53/56/57/58/59 origins.
**Origin1878 отсутствует; reconstructed1879/0804 используют Corporate из main,
а не actual2613.** CI PASS схемы означает только эти12 синтетических сценариев.

## Corporate: отдельное решение, без автоматической замены

| Проверка | Candidate0901 | Production2613 |
|---|---|---|
| Происхождение | Patched2610/API1.26, release0.40.10-bms.1 | Code/DB2613 свежеподтверждены,full source tree NOT VERIFIED |
| bridge API | `bridge.php:18` API1.26 | bridge API1.26 и имена методов подтверждены;signatures/behavior NOT VERIFIED |
| task/program projections | Методы bridge:107–144 присутствуют | Signature/behavior NOT VERIFIED |
| ERP mappings/audit/exchange | Исправления PR52/56;150Corporate tests PASS | Сохранение поведения2613 не доказано |
| company mapping | bridge:413 + provider mapping checks | Stable mapping/UUID reconciliation NOT RUN |
| Upgrade |2610→0901 code-only, нет reconciliation2611–2613 | Численно0901 выше2613 — Moodle может разрешить опасную замену |
| UI board/paging/diagnostics | Нет полного2613набора | Stage2613 file-hash inventory содержит дополнительные файлы board/paging/diagnostics; поведение не проверено |

[Provider](../../local/kbacompany/classes/local/task_provider/kbacorporate_impl_task_provider.php):25–32
проверяет API≥1.22 и методы `list_implementation_task_projections`,
`get_implementation_task_projection`, `get_company_by_bitrix_id`;39–83 требует стабильную company
mapping, активную source binding и отдельную Corporate authorization. Наличие
этих методов — минимальный interface gate, не доказательство поведения всех модулей.
[Corporate bridge](../../local/kbacorporate/classes/local/bridge.php),
[provenance checker](../../tools/check_corporate_api126.py) фиксируют только candidate.

[Stage2613 hash comparison](../reconcile/corporate2613-source-comparison.json)
показывает830 файлов против817 у2610/0901. Относительно2610:16добавленных,
3отсутствующих,11изменённых; относительно0901 изменённых26. Среди дополнений:
`implementation_board_query.php`, `implementation_acceptance_metrics.php`, paging
и program projection tests. Это stage41 metadata, **не production source equivalence**;
полное содержание/семантика различий NOT VERIFIED.

Безопасная базовая стратегия: **сохранить установленный Corporate2613**, дополнить
полученный fingerprint сигнатурами,проверить full source/behavior и BMS0906 с ним отдельно.
Если несовместим — самостоятельная reconciliation-ветка Corporate, карта сохранённых
функций2613, DDL/data/API/permission diff, полный Corporate/BMS regression и решение
владельца. Не копировать0901 из CI на production ради зелёной матрицы.

## Модули и зависимые компоненты

| Область | Подтверждено в CI0906/0901 | Не закрыто для production |
|---|---|---|
| Moodle4.1.22/PHP8.1.34/Percona8.0.46-37 | Изолированная матрица фактически выполнена | Web SAPI/full Core hash;CLI metadata и installed DB plugin versions получены |
| KBA Theme/Boost, App Shell/CRUD | Static+некоторые renderer tests | Theme2026092213 подтверждена;legacy/owned, navigation, mobile, forms/session/CSRF NOT RUN |
| Tasks / Battle Plans | task12/48,battle14/74,Kanban10/90, batch/deadline/member PASS |3 Battle Plan/export failures; browser workflows/performance |
| Role Intelligence | role intelligence4/16,readiness3/9 PASS | Полнота live R1/R2/source-tree и UI/nonadmin2company NOT VERIFIED |
| Learning/queues | development5/65,queue9/99, profile1/5 PASS | Курсы/gradebook/completion/competency и plugin-stack реального сайта |
| Documents/employees | documents10/53,sync11/57 PASS | Реальные file permissions,membership/attachments и export scenarios |
| Processes/metrics/finance | Часть regression пройдена | Process/OFS/indicator/finance19-list содержит ошибки; влияние не принято |
| Communications/ERP |4communication suites,Corporate150/962 PASS | Installed2613,Bitrix24 endpoints/retries/idempotency и external side effects |
| UUID/RBAC/tenant | isolation2/66,integrated4/19,membership8/110 PASS | HTTP GET/POST,session revocation,real file URLs и sibling bypasses NOT RUN |
| Cron/queues | Изолированные queue tests | Cron scheduler/adhoc backlog,locks/retry и последствия внешних вызовов |

Весь Moodle/Core и sibling stack не входят в INSTALL ZIP. BMS `requires=2022041900`
— нижний installation threshold, не сертификация всех PHP/Moodle комбинаций.
Не менять Core, тему или зависимости в ходе этого документационного аудита.

## Реестр рисков

P0 gate означает блокирующую недостаточность доказательств, а не автоматически
обнаруженную эксплуатационную уязвимость. P1 ошибки нельзя считать безопасными
без проверки. P2 допустим только после доказанного неопасного влияния; сейчас
ни одна неизвестная ошибка в P2 не переведена.

| ID / уровень | Доказательство | Влияние | Предотвращение | Критерий закрытия |
|---|---|---|---|---|
| R01 P0 gate recovery | Backup set NOT VERIFIED, restore NOT RUN | Невозможность вернуть курсы/файлы/бизнес-данные | Запрет установки, согласованный coherent backup+isolated restore | Успешный restore DB+code+data+config, измеренные RTO/RPO |
| R02 P0 Corporate |0901 patched2610;stage2613 feature diff;actual source неизвестен | Утрата функций, API/mapping/authorization regressions | Не заменять2613; отдельная совместимость | Exact2613+0906 acceptance либо утверждённый reconciliation |
| R03 P0 gate migrations | Нет actual1878 origin;live metadata:adminitem2fields +11decimal types не исправляются0906 | DDL error, длительные locks, частичное изменение схемы | Полный metadata diff, restore rehearsal, capacity/lock budget | Exact-source/data/schema upgrade + repeat + rollback rehearsal PASS |
| R04 P0 gate tenant/auth | HTTP/session/file ACL NOT RUN | Доступ к чужой компании/файлам или несанкционированная запись | Закрытая изолированная двухкомпанейская приёмка | GET/POST/files/revocation tests и независимый security sign-off |
| R05 P0 gate education | Full sibling/theme/Core pair NOT VERIFIED | Повреждение входа/курсов/оценок/записей на обучение | Сохранить Core/siblings, hashes, smoke on exact clone | Login/course/enrolment/gradebook/completion/files PASS |
| R06 P1 BMS defects |19 exact failed IDs,1error18fail | Неполные business/user scenarios; влияние неизвестно | Исправления узкими PR, без ослабления assertions | Все обязательные тесты PASS либо доказанный scoped acceptance владельца; P0 исключать нельзя |
| R07 P1 Web/runtime |CLI свежеподтверждён,Web не проверен | Неожиданные PHP errors/timeouts/uploads | Подтвердить domain handler/ini/extensions | Совпадающий runtime профиль, web smoke |
| R08 P1 cron/integrations |Cron/Bitrix/ERP operational NOT RUN | Дубли/потеря задач, реальные уведомления/платежи | Инвентаризация writers; deny-egress restore; controlled canary | Schedule/identity/locks/idempotency/backlog/retries подтверждены |
| R09 P1 performance/capacity |Host-level disk/RAM получены;account quotas/inodes/DDL benchmarks неизвестны | Outage/disk full/metadata locks | Capacity расчёт и rehearsal на сопоставимом объёме | Запас по каждому volume/quota, measured lock/duration в окне |
| R10 P0 gate artifact identity |PR60/62 разные ZIP,один0906 | Установка непроверенного кандидата | Pin source+manifest+SHA256; запрет latest | Exact bytes approved+verified on target before install |
| R11 P1 operational control |Нет rehearsal/window/operator evidence | Ошибки порядка backup/cron/rollback | Двухэтапное owner approval, журнал, ответственные | Подписанный runbook с конкретными путями/окном/rollback trigger |

## Остаточные19 падений: не признаны безопасными

Ниже точные IDs из PR62. Для каждого влияние требует отдельной проверки;
source-first diagnosis не доказывает последующий путь. Company/manager policy
имеет P0 gate на авторизацию, остальные пока P1 review. Runtime defect из
устаревшего version assertion автоматически не выводится.

| Test ID | Категория / предварительный риск | Условие закрытия |
|---|---|---|
| `local_kbacompany\battle_plan_indicator_service_test::test_historical_projection_uses_last_52_periods_relative_to_plan_week` | Battle Plans and tasks; P1 / impact review | Доказать доменный контракт; исправить причину без ослабления проверок; focused+full regression и UI при затронутом сценарии |
| `local_kbacompany\battle_plan_management_overview_service_test::test_contract_is_schema_neutral_read_model` | Contracts and stale tests; P1 / impact review | Доказать доменный контракт; исправить причину без ослабления проверок; focused+full regression и UI при затронутом сценарии |
| `local_kbacompany\battle_plan_word_export_service_test::test_model_projects_previous_partial_week_and_current_target` | Battle Plans and tasks; P1 / impact review | Доказать доменный контракт; исправить причину без ослабления проверок; focused+full regression и UI при затронутом сценарии |
| `local_kbacompany\battle_plan_word_export_service_test::test_ooxml_contains_real_word_document_and_cyrillic_text` | Battle Plans and tasks; P1 / impact review | Доказать доменный контракт; исправить причину без ослабления проверок; focused+full regression и UI при затронутом сценарии |
| `local_kbacompany\bms_navigation_test::test_only_allowlisted_context_is_propagated` | BMS UI and App Shell; P1 / impact review | Доказать доменный контракт; исправить причину без ослабления проверок; focused+full regression и UI при затронутом сценарии |
| `local_kbacompany\bms_page_shell_service_test::test_business_architecture_semantics_follow_bms_methodology` | BMS UI and App Shell; P1 / impact review | Доказать доменный контракт; исправить причину без ослабления проверок; focused+full regression и UI при затронутом сценарии |
| `local_kbacompany\bms_page_shell_service_test::test_contract_versions_are_aligned` | BMS UI and App Shell; P1 / impact review | Доказать доменный контракт; исправить причину без ослабления проверок; focused+full regression и UI при затронутом сценарии |
| `local_kbacompany\company_core_test::test_contract_declares_existing_source_without_duplication` | Contracts and stale tests; P1 / impact review | Доказать доменный контракт; исправить причину без ослабления проверок; focused+full regression и UI при затронутом сценарии |
| `local_kbacompany\company_core_test::test_permission_contract_centralises_company_role_policies` | Contracts and stale tests; P1 / impact review | Доказать доменный контракт; исправить причину без ослабления проверок; focused+full regression и UI при затронутом сценарии |
| `local_kbacompany\financial_statement_service_test::test_bdr_and_bdds_calculations_match_bms_finance_rules` | Metrics and finance; P1 / impact review | Доказать доменный контракт; исправить причину без ослабления проверок; focused+full regression и UI при затронутом сценарии |
| `local_kbacompany\indicator_registry_service_test::test_contract_keeps_one_canonical_indicator_registry` | Contracts and stale tests; P1 / impact review | Доказать доменный контракт; исправить причину без ослабления проверок; focused+full regression и UI при затронутом сценарии |
| `local_kbacompany\integration_event_service_test::test_event_outbox_lifecycle_is_independent_from_legacy_command_status` | Contracts and stale tests; P1 / impact review | Доказать доменный контракт; исправить причину без ослабления проверок; focused+full regression и UI при затронутом сценарии |
| `local_kbacompany\market_model_service_test::test_contract` | Contracts and stale tests; P1 / impact review | Доказать доменный контракт; исправить причину без ослабления проверок; focused+full regression и UI при затронутом сценарии |
| `local_kbacompany\ofs_service_test::test_ofs_projects_company_unit_function_products_and_internal_customer` | Processes and documents; P1 / impact review | Доказать доменный контракт; исправить причину без ослабления проверок; focused+full regression и UI при затронутом сценарии |
| `local_kbacompany\process_model_service_test::test_complete_sipoc_bpmn_model_publishes_and_activity_becomes_semantic` | Processes and documents; P1 / impact review | Доказать доменный контракт; исправить причину без ослабления проверок; focused+full regression и UI при затронутом сценарии |
| `local_kbacompany\process_service_test::test_process_hierarchy_publish_and_cross_functional_links` | Processes and documents; P1 / impact review | Доказать доменный контракт; исправить причину без ослабления проверок; focused+full regression и UI при затронутом сценарии |
| `local_kbacompany\project_execution_service_test::test_management_planning_does_not_link_to_retired_editor` | BMS UI and App Shell; P1 / impact review | Доказать доменный контракт; исправить причину без ослабления проверок; focused+full regression и UI при затронутом сценарии |
| `local_kbacompany\task_manager_workspace1853_test::test_non_manager_never_receives_manager_workspace` | Rights and company membership; P0 gate / policy review | Доказать доменный контракт; исправить причину без ослабления проверок; focused+full regression и UI при затронутом сценарии |
| `local_kbacompany\task_roles_workspace1840_test::test_compact_workspace_escapes_search_and_has_progressive_get_fallback` | BMS UI and App Shell; P1 / impact review | Доказать доменный контракт; исправить причину без ослабления проверок; focused+full regression и UI при затронутом сценарии |

Три предложенных следующих contract-tests — overview, indicator_registry,
market_model. Сохранять explicit versions и смысловые assertions; не подменять
ожидаемое production constants. Их исправление само по себе не закрывает R01–R05.

## Минимальные изолированные acceptance cases после отдельного разрешения

- Два tenant A/B, обычный сотрудник, reviewer,manager и outsider; прямые URL/POST,
  неверный sesskey, чужой UUID, отозванное членство в той же сессии; отсутствие
  чужих чтений/записей/files и сохранение audit/revision.
- Обучение: enrollment и course progress, assignment grade и competency confirmation
  проверять отдельно; очередь review, разрешённый reviewer и запрещённый outsider.
- Tasks/Battle Plan: start/submit/accept/close, даты/периоды, stale revision,
  generated provenance, deadlines/full counts, export/attachments.
- Documents/processes/metrics: membership sync,доступ кфайлам,publication/lifecycle,
  company-scoped calculations, формы CRUD;19failures разрешить по точным IDs.
- Коммуникации/ERP/Bitrix: только локальные fakes/sinks, без боевых адресатов;
  mapping, independent Corporate ACL, retries/idempotency, очередь и диагностика.
- Owner UI acceptance — сначала изолированная копия на закрытом hostname.
  Желание владельца тестировать UI не разрешает экспериментировать на production
  до закрытия P0 gates и отдельного deployment approval.

## Дополнительная read-only команда Corporate fingerprint

Только для администратора в доверенной production-консоли. Читает ограниченный
набор code files без include/bootstrap, не загружает config и не выводит source.
Это предварительный fingerprint; signatures/semantics всё равно проверяются
отдельно по разрешённому source review и изолированным тестам.

```sh
ROOT=/var/www/p706494/data/www/edu.kzn.mba
/usr/bin/php -n /dev/stdin "$ROOT" <<'PHP'
<?php
$root=$argv[1].'/local/kbacorporate/';
foreach (['classes/local/bridge.php','classes/local/implementation_task_projection.php',
          'classes/local/implementation_task_discussion.php','classes/local/implementation_board_query.php',
          'classes/local/implementation_acceptance_metrics.php','implementation_board.php',
          'implementation_curator.php','db/install.xml','db/upgrade.php'] as $file) {
    $path=$root.$file;
    if (!is_file($path) || is_link($path)) {
        echo json_encode(['file'=>$file,'status'=>'UNAVAILABLE']), PHP_EOL; continue;
    }
    $text=file_get_contents($path);
    preg_match_all('/public\s+(?:static\s+)?function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/',$text,$methods);
    echo json_encode(['file'=>$file,'sha256'=>hash('sha256',$text),
        'public_method_names'=>array_values(array_unique($methods[1]))]), PHP_EOL;
}
PHP
```

Регулярное выражение показывает только имена объявлений, не argument/return
contracts и не поведение; commented text может дать ложное совпадение. Поэтому
результат не является compatibility PASS. Не включать исходники с secrets/PII
в GitHub; приватное сравнение API1.26/2613 должно учитывать реальные типы,
параметры,пагинацию,projection fields,company mapping и Corporate authorization.

## Новые подтверждённые блокеры после live refresh

Источник: [schema diff](RC30_PRODUCTION_SCHEMA_DIFF.json), снимок20:55:42Z.
142 BMS таблицы присутствуют. Помимо известных executionstatus и ordered index,
0906 не содержит достижимого repair для:

- `local_kbacompany_adminitem.starttime` и `.duetime` (отсутствуют; fresh varchar5 NOT NULL default empty).
- `finbudfact.actualamount`, `finbudline.planamount/limitamount`, `finline.amount`: decimal(18,0) вместо(18,2).
- `statbind.weight`: decimal(8,0) вместо(8,3).
- `statnorm.targetvalue/minval/maxval`: decimal(18,0) вместо(18,6).
- `statpolicy.dangerdecline/affluencegrowth/powerlevel`: decimal(8,0) вместо(8,3).

Это подтверждённые отличия COLUMN_TYPE, не display-width/default formatting.
При фиксированной precision увеличение scale **уменьшает целый диапазон**:
18→16/12 и8→5 цифр. Нельзя считать ALTER безопасным widening. По metadata нельзя
доказать отсутствие overflow или прежнего округления; потерянную дробную часть
изменение типа не восстановит. Business values не читались.

R03 теперь не только unknown gate: actual drift доказан. До установки нужен
отдельный согласованный repair/ADR при выборе правил типов, fail-closed range
checks в разрешённом clone, проверки сохранения значений и exact1878→newcandidate
upgrade/repeat на обеих DB. Никаких ручных ALTER/version reset в production.
Существующий synthetic CI этот drift не моделирует; прежний PASS не отменяет его.

Corporate41 actual schema имеет2default differences:devplan.status active vsCI draft,
member.isemployee0 vsCI1. Defaults проверены поmetadatahashes,не поbusinessrows.
Не менять их автоматически:особенно isemployee влияет на admission semantics.
Состав таблиц/колонок и нормализованные types/nullability/auto_increment совпадают;
это не отменяет полноценный API/behavior review2613. См.[refresh](RC30_PRODUCTION_REFRESH.md).
