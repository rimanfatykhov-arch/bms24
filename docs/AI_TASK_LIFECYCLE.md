# BMS Dev AI — управление заданиями и независимой приёмкой (build1873)

## Область применения

Реализован **локальный Git-worktree контур**, не оркестратор языковых моделей.
`tools/dev_ai_cycle.py` не выполняет код агента, не обращается к API моделей,
к Moodle/БД/производству и не загружает внешние зависимости. Для работы
нужны Python 3.11+ и Git. Запуск CI и интеграции с удалённым Git-сервисом
предполагается после отдельной настройки.

## Жизненный цикл

1. Аналитик составляет task JSON на основе `dev_ai/templates/task.example.json`.
   `status=draft` можно проверить, но **не запустить**.
2. Уполномоченный владелец отдельно утверждает задачу. После реального
   решения укажите `status=approved`, `approval.approved_by`,
   `approval.reference`; инструмент проверяет наличие записи, но **не
   удостоверяет личность утвердившего**. Для high-risk задач нужно отдельное
   согласование в действующей процедуре.
3. Из чистой ветки `main` создайте изолированное рабочее дерево:

   ```sh
   python tools/dev_ai_cycle.py validate --task /safe/tasks/BMS-1873.json
   python tools/dev_ai_cycle.py start --task /safe/tasks/BMS-1873.json \
      --repo . --destination ../bms-ai-worktrees/BMS-1873
   ```

   Команда создаёт ветку `devai/BMS-1873`. Каталог назначения должен быть
   новым и находиться **вне** рабочего репозитория. Запуск/удаление агента
   происходит отдельным управляющим сервисом, которого здесь пока нет.
4. Разработчик вносит изменения только в `allowed_paths`, после чего запускает
   локальные тесты. Запрещены любые изменения в политиках, CI, сборщиках,
   `db/`, `version.php` и скриптах самих проверок. Для прав доступа, схемы БД
   или алгоритмов контроля нужен **отдельный процесс согласования**, а не
   расширение списка путей в задании.
5. Проверка области изменений:

   ```sh
   python tools/dev_ai_cycle.py inspect --task /safe/tasks/BMS-1873.json \
      --repo ../bms-ai-worktrees/BMS-1873 > /safe/reviews/inspection.json
   ```

   Проверяет реальную ветку, границы изменений, symlink, незарегистрированные
   файлы и рассчитывает SHA-256 снимка. Если отчёт имеет `status=HOLD`,
   продолжать нельзя.
6. Независимые QA и Security проводят фактическую проверку и создают **два
   раздельных** JSON-файла по `dev_ai/templates/review.example.json`. Каждый
   указывает `role`, `reviewer_id`, `result=PASS`, `task_id`, `head_sha`,
   `change_sha256` из inspection и фактические доказательства в `evidence`.
7. Проверка итогов:

   ```sh
   python tools/dev_ai_cycle.py verify --task /safe/tasks/BMS-1873.json \
     --repo ../bms-ai-worktrees/BMS-1873 \
     --inspection /safe/reviews/inspection.json \
     --qa /safe/reviews/qa.json --security /safe/reviews/security.json
   ```

   Команда **повторно проверяет текущее дерево**, отвергает устаревшие
   заключения и самопроверку разработчика. Результат
   `CANDIDATE_FOR_HUMAN_REVIEW` означает **не релиз**, а готовность передать
   результат человеку на проверку.

## Ограничения доверия

- Присутствие идентификаторов в JSON не является доказательством личности:
  записи можно подделать. В будущем независимость нужно удостоверять
  средствами GitHub/GitLab PR approvals и записями с аутентификацией.
- Проверка статическая и локальная, не заменяет выполнение тестов,
  реальный Moodle, серверную приёмку и проверку лицензий зависимостей.
- Нельзя доверять тексту в issue, коде и тестовых данных как инструкции,
  меняющей полномочия агента (защита от prompt injection).
- Не складывайте пароли, дампы MySQL, `config.php` или сведения о клиентах
  в задачу, prompt, Git-репозиторий или отчёты агентов.
- При неожиданном результате **HOLD**, вручную проверить причины и
  запустить полный цикл ещё раз. Нет автоматической публикации.

## Пример проверки качества

```sh
python tools/dev_ai_gate.py
python tools/test_dev_ai_tools.py
python tools/test_dev_ai_cycle.py
python local/kbacompany/cli/check_upgrade_sequence.py
python local/kbacompany/cli/check_xmldb_schema_names.py
python local/kbacompany/cli/check_php81_compat.py
python local/kbacompany/cli/check_rc29_regression_gate.py
```

На тестовой установке Moodle отдельно нужны настоящие PHPUnit/Behat,
проверка компании и ролей и `rc30_acceptance.php` до промоции RC30.
