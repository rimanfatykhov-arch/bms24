# PR67: независимая классификация 19 baseline failures PR66

Проверено по исходным JUnit обоих движков, а не по итоговому зелёному статусу workflow. Evidence root: `outputs/pr66-acceptance/` относительно рабочего каталога BMS (вне Git). Candidate source: `e7d8cc06d99ba407662fc9aed86dfc526c583521`. Совпадают все 19 identities и типы исключений.

- `mariadb-final/local_kbacompany-junit.xml`: 618 leaf tests, 7274 assertions, 1 errors, 18 failures, 0 skipped; SHA256 `26765e03806a161fe85f90c3badb6e2fbd6ccc0be9b27bd00e5aa4ae0652edcf`.
- `percona-final/local_kbacompany-junit.xml`: 618 leaf tests, 7274 assertions, 1 errors, 18 failures, 0 skipped; SHA256 `5bcce13ea2347862a96c4f33f0568d0c7d47a672fc663b2d9ac01f53d6a697a9`.

## Границы вывода

Это классификация **первого наблюдаемого failure** каждого теста. Assertions после него могли не выполниться; гипотеза о fixture не является доказательством исправности сервиса. P0 validation означает приоритет проверки авторизации, а не доказанную утечку. PR67 меняет только три выбранных contract-теста и CI/focused invocation; runtime, схема и версии плагинов не изменены. Полная acceptance остаётся HOLD до новых результатов и устранения остальных блокеров. Production/staging здесь не проверялись и не менялись.

## Все identities

В заголовках сохранён точный JUnit classname (с точкой); соответствующий PHP namespace использует обратную косую черту. Пути тестов ниже относительно `local/kbacompany/tests/`; сервисы — `local/kbacompany/classes/local/`.

### 1. `local_kbacompany.battle_plan_indicator_service_test::test_historical_projection_uses_last_52_periods_relative_to_plan_week`

- Наблюдение: Failed asserting that two strings are not identical.
- Категория: **Fixture hypothesis**; приоритет **P1**.
- Evidence / диагноз: Две предыдущие точки недостаточны для CLASSIFICATION_FACTS=3 (indicator_trend_service.php). Тест: `test_historical_projection_uses_last_52_periods_relative_to_plan_week` в `battle_plan_indicator_service_test.php`.
- Следующая проверка: Добавить третью предыдущую точку; отдельно проверить 52 периода, будущие точки и недостаточную историю.

### 2. `local_kbacompany.battle_plan_management_overview_service_test::test_contract_is_schema_neutral_read_model`

- Наблюдение: Failed asserting that two strings are identical.
- Категория: **Confirmed stale contract; selected PR67**; приоритет **P2**.
- Evidence / диагноз: Ожидание 0.5.10, сервис объявляет 0.5.11. Тест: `test_contract_is_schema_neutral_read_model` в `battle_plan_management_overview_service_test.php`.
- Следующая проверка: Сохранить schema-neutral/read-model/ACL assertions; проверить literal 0.5.11 и canonical task source.

### 3. `local_kbacompany.battle_plan_word_export_service_test::test_ooxml_contains_real_word_document_and_cyrillic_text`

- Наблюдение: Undefined array key "observer"
- Категория: **Fixture hypothesis; runtime input-contract boundary**; приоритет **P1**.
- Evidence / диагноз: Ручная OOXML model не содержит labels.observer; штатный model builder задаёт этот ключ. Тест: `test_ooxml_contains_real_word_document_and_cyrillic_text` в `battle_plan_word_export_service_test.php`.
- Следующая проверка: Построить полную модель; сохранить Word ZIP/XML и кириллицу. Отдельно решить поддержку неполной модели; warning не подавлять.

### 4. `local_kbacompany.battle_plan_word_export_service_test::test_model_projects_previous_partial_week_and_current_target`

- Наблюдение: Failed asserting that '42000.00 (Actual for 1 of 7 days)' contains "Факт за 1 из 7 дней".
- Категория: **Confirmed locale-dependent expectation**; приоритет **P2**.
- Evidence / диагноз: Английская строка Actual for 1 of 7 days сравнивается с русским литералом. Тест: `test_model_projects_previous_partial_week_and_current_target` в `battle_plan_word_export_service_test.php`.
- Следующая проверка: Использовать языковой контракт или явно заданную RU locale; сохранить сумму и 1/7 дней.

### 5. `local_kbacompany.bms_navigation_test::test_only_allowlisted_context_is_propagated`

- Наблюдение: Failed asserting that true is identical to false.
- Категория: **Contract/policy review required**; приоритет **P1**.
- Evidence / диагноз: coordweek присутствует в действующем allowlist, тест ожидает его отсутствие (bms_navigation_test.php:36). Тест: `test_only_allowlisted_context_is_propagated` в `bms_navigation_test.php`.
- Следующая проверка: Подтвердить разрешённый контекст; проверить сохранение company scope и отбрасывание неизвестных/опасных параметров.

### 6. `local_kbacompany.bms_page_shell_service_test::test_contract_versions_are_aligned`

- Наблюдение: Failed asserting that two strings are identical.
- Категория: **Version mismatch confirmed; compatibility not certified**; приоритет **P2**.
- Evidence / диагноз: Ожидание 0.4.9, фактически 0.6.0. Тест: `test_contract_versions_are_aligned` в `bms_page_shell_service_test.php`.
- Следующая проверка: Сверить shell contracts и историю; не заменить все literals без проверки схемы и поведения.

### 7. `local_kbacompany.bms_page_shell_service_test::test_business_architecture_semantics_follow_bms_methodology`

- Наблюдение: Failed asserting that two arrays are identical.
- Категория: **Domain decision required**; приоритет **P1**.
- Evidence / диагноз: Фактическая taxonomy дополнена metrics и informationcenter. Тест: `test_business_architecture_semantics_follow_bms_methodology` в `bms_page_shell_service_test.php`.
- Следующая проверка: Согласовать бизнес-таксономию; проверить маршруты, доступ и отсутствие устаревших разделов.

### 8. `local_kbacompany.company_core_test::test_contract_declares_existing_source_without_duplication`

- Наблюдение: Failed asserting that two strings are identical.
- Категория: **Version mismatch confirmed; compatibility not certified**; приоритет **P2**.
- Evidence / диагноз: Ожидание company contract 0.6.0, фактически 0.6.7. Тест: `test_contract_declares_existing_source_without_duplication` в `company_core_test.php`.
- Следующая проверка: Проверить canonical source/no duplication и историю изменений перед обновлением ожидания.

### 9. `local_kbacompany.company_core_test::test_permission_contract_centralises_company_role_policies`

- Наблюдение: Failed asserting that two strings are identical.
- Категория: **Version mismatch confirmed; ACL review required**; приоритет **P1**.
- Evidence / диагноз: Ожидание permission contract 0.5.1.3, фактически 0.7.0. Тест: `test_permission_contract_centralises_company_role_policies` в `company_core_test.php`.
- Следующая проверка: Проверить role policies, revoked/inactive membership, cross-company denial; literal не заменяет ACL acceptance.

### 10. `local_kbacompany.financial_statement_service_test::test_bdr_and_bdds_calculations_match_bms_finance_rules`

- Наблюдение: Failed asserting that 500.0 matches expected 450.0.
- Категория: **Fixture arithmetic hypothesis**; приоритет **P1**.
- Evidence / диагноз: Ожидается netprofit 450, получено 500; fixture даёт 1000-300-200+50-20-30=500. Тест: `test_bdr_and_bdds_calculations_match_bms_finance_rules` в `financial_statement_service_test.php`.
- Следующая проверка: Проверить отдельно доходы/расходы/налог и формулу; сохранить BDR/BDDS промежуточные утверждения, отрицательные cash buckets.

### 11. `local_kbacompany.indicator_registry_service_test::test_contract_keeps_one_canonical_indicator_registry`

- Наблюдение: Failed asserting that two strings are identical.
- Категория: **Confirmed stale contract; selected PR67**; приоритет **P2**.
- Evidence / диагноз: Ожидание 0.5.2, сервис объявляет 0.5.5.2. Тест: `test_contract_keeps_one_canonical_indicator_registry` в `indicator_registry_service_test.php`.
- Следующая проверка: Сохранить canonical registry; проверить literal и append-only statval history.

### 12. `local_kbacompany.integration_event_service_test::test_event_outbox_lifecycle_is_independent_from_legacy_command_status`

- Наблюдение: Failed asserting that 2 is identical to 1.
- Категория: **Fixture lifecycle hypothesis**; приоритет **P1**.
- Evidence / диагноз: Ожидается attempts=1, фактически 2; fail_event и complete_event увеличивают счётчик, retry_event сохраняет. Тест: `test_event_outbox_lifecycle_is_independent_from_legacy_command_status` в `integration_event_service_test.php`.
- Следующая проверка: Проверить 0→1→1→2 и повторный complete без роста, legacy command status независимо; согласовать смысл attempts.

### 13. `local_kbacompany.market_model_service_test::test_contract`

- Наблюдение: Failed asserting that two strings are identical.
- Категория: **Confirmed stale contract; selected PR67**; приоритет **P2**.
- Evidence / диагноз: Ожидание 0.3.5, сервис объявляет 0.4.11. Тест: `test_contract` в `market_model_service_test.php`.
- Следующая проверка: Сохранить objects/schema hash; добавить company scope, immutable published revisions, запрет CRM/ERP mutation.

### 14. `local_kbacompany.ofs_service_test::test_ofs_projects_company_unit_function_products_and_internal_customer`

- Наблюдение: Failed asserting that true is false.
- Категория: **Domain/feature contract review required**; приоритет **P1**.
- Evidence / диагноз: processes_available фактически true, ожидалось false (ofs_service.php). Тест: `test_ofs_projects_company_unit_function_products_and_internal_customer` в `ofs_service_test.php`.
- Следующая проверка: Подтвердить интеграцию OFS/processes и доступность по компании, а не просто инвертировать boolean.

### 15. `local_kbacompany.process_model_service_test::test_complete_sipoc_bpmn_model_publishes_and_activity_becomes_semantic`

- Наблюдение: Failed asserting that two strings are identical.
- Категория: **Version mismatch confirmed; lifecycle still unverified**; приоритет **P1**.
- Evidence / диагноз: Ожидание 0.3.1, фактически 0.4.4; первый failure блокирует дальнейшие assertions. Тест: `test_complete_sipoc_bpmn_model_publishes_and_activity_becomes_semantic` в `process_model_service_test.php`.
- Следующая проверка: Проверить историю контрактов, SIPOC/BPMN публикацию и semantic activity, неполную модель и foreign-company links.

### 16. `local_kbacompany.process_service_test::test_process_hierarchy_publish_and_cross_functional_links`

- Наблюдение: Failed asserting that two strings are identical.
- Категория: **Version mismatch confirmed; lifecycle still unverified**; приоритет **P1**.
- Evidence / диагноз: Ожидание 0.3.0, фактически 0.4.0. Тест: `test_process_hierarchy_publish_and_cross_functional_links` в `process_service_test.php`.
- Следующая проверка: Проверить hierarchy/publish/cross-functional links и tenant isolation после устранения первого version mismatch.

### 17. `local_kbacompany.project_execution_service_test::test_management_planning_does_not_link_to_retired_editor`

- Наблюдение: Failed asserting that two arrays are identical.
- Категория: **Navigation contract review required**; приоритет **P1**.
- Evidence / диагноз: Навигация дополнена coordweek/decisionsregistry перед week/battleplan/cycles. Тест: `test_management_planning_does_not_link_to_retired_editor` в `project_execution_service_test.php`.
- Следующая проверка: Подтвердить новые маршруты; сохранить запрет retired editor и ACL каждого назначения.

### 18. `local_kbacompany.task_manager_workspace1853_test::test_non_manager_never_receives_manager_workspace`

- Наблюдение: Failed asserting that '<header class="bms24-task-header">\n
- Категория: **Possible presentation defect; security decision required**; приоритет **P0 validation**.
- Evidence / диагноз: HTML содержит display=manager для non-manager; renderer имеет restrictednote guard. Сам failure не доказывает выдачу закрытых данных. Тест: `test_non_manager_never_receives_manager_workspace` в `task_manager_workspace1853_test.php`.
- Следующая проверка: Проверить отрицательные HTTP/data cases non-manager, revoked/inactive и foreign tenant; определить fallback display. Не ослаблять ACL ради HTML assertion.

### 19. `local_kbacompany.task_roles_workspace1840_test::test_compact_workspace_escapes_search_and_has_progressive_get_fallback`

- Наблюдение: Failed asserting that '<header class="bms24-task-header">\n
- Категория: **Locale-dependent fixture hypothesis**; приоритет **P2**.
- Evidence / диагноз: Тест ищет русский Все мои роли в английском HTML. Тест: `test_compact_workspace_escapes_search_and_has_progressive_get_fallback` в `task_roles_workspace1840_test.php`.
- Следующая проверка: Сохранить XSS escaping search, GET fallback и role filters; сделать ожидание locale-aware, проверить RU и EN.

## Review трёх изменений PR67

В overview заменён только устаревший literal и добавлены утверждения `only_accessible_battle_plans_are_aggregated` / canonical task source. В indicator registry добавлены canonical history `local_kbacompany_statval` и `observations_remain_append_only`. В market добавлены required company scope, immutable published revisions и отсутствие CRM/ERP fact mutation. Исходные assertions сохранены; skip/suppression и подмена ожидаемой версии вызовом сервиса отсутствуют. Новые инварианты совпадают с текущими декларациями сервисов; сами по себе декларации не доказывают runtime ACL/immutability. Историческое обоснование неизменности сервисов и отдельный `reproduce_contracts.php` предоставлены архитектурным review; результат полной повторной матрицы следует фиксировать отдельно после завершения.

Остальные 16 identities не исправлялись в этом increment. Предлагаемая следующая последовательность: сначала targeted security validation manager workspace, затем ограниченные Word/locale и finance/outbox fixture increments с отрицательными проверками; отдельным решением — расширенные навигационные/процессные контракты. Ни одно ожидание не следует массово синхронизировать с текущим output без проверки предметного смысла.
