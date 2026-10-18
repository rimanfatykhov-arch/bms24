# BMS24 RC29 / build2026101642 — UI Architecture W3: Task Center Own Presentation

**База:** `build2026101641`. **Статус:** staged UI release candidate; не FINAL. **Дата:** 18.10.2026.

## Функциональность

- Task Center (`tasks.php`) постепенно переводится на plugin-owned UI: `bms24_task_header.mustache`, `bms24_task_tabs.mustache`, `bms24_task_toolbar.mustache`, `bms24_task_panel.mustache`, визуальные стили `bms_ui/task_center.css`. Собственные заголовки, вкладки, карточки и панели.
- Список задач использует типизированный и экранируемый GET-фильтр UI Kit W2 (все прежние параметры: `uuid`, `scope`, `display`, `q`, `status`, `stream`, `sourcekind`), сохраняет поиск с `maxlength=200`, переключатели видов, ссылки на задачи, пагинацию, канбан и аналитику.
- Поля создания/редактирования и служебных действий в режиме `owned` используют собственные `bms24-ui-input/select/textarea`, но сохраняют HTML `id`, `name`, `required`, `aria-invalid`, `aria-describedby`, список выбора должности и внешние AJAX-контракты; сохраняются `sesskey`, `requestkey`, `revision`, `POST` и все бизнес-права.
- Статусы Task Center, включая `open` и `cancelled`, поддержаны типизированным UI Kit. Ссылки компонентов принимают только `moodle_url`; сырой пользовательский текст выводится экранированно.
- Режим `legacy` оставлен без изменений для отката: администратор устанавливает `local_kbacompany/bms24_shell_mode=legacy`. Обратное переключение не требует изменения данных.
- **Никаких изменений** модели задач, UUID, Task/Recurring/Battle Plan, Position Coverage, Moodle RBAC, оргструктуры и схемы БД.

## Технические изменения

- Плагин: `local_kbacompany` / версия `2026101642` / no-DDL savepoint `2026101642`.
- CSS `bms_ui/task_center.css` подключается только в opt-in own shell и имеет namespace `.bms24-app`. Moodle core, KBA Theme, Bootstrap/global CSS не модифицируются.
- Важно: Task Center всё ещё содержит ряд legacy DOM-хуков (`bms-tui`, `bms-task2-...`) ради совместимости форм/JS и исходного CSS. Это **первый срез W3**, не финальная отвязка. Следующие W3-срезы переводят остальные формы/виды и другие модули.

## Локальная QA

- `php -l`: **397/397 PASS**.
- Переводы RU/EN: **8085/8085**, идентичны.
- XMLDB неизменён и валиден; PHP upgrade checkpoint монотонный.
- `tinycss2`: **206 scoped CSS selectors** проверены, нет глобальных top-level или media styles.
- Изолированный PHP presentation smoke: **16/16 PASS**, owned templates/filter/status/form IDs, legacy parity. Moodle PHPUnit файл добавлен, но **не запускался**.
- Standalone Chromium preview: 1440 и 390 px без горизонтального переполнения документа; таблица 390 px прокручивается внутри. Это **макет**, не реальная страница Moodle.
- Архивы проходят ZIP CRC, встроенный `version.php`, SHA256.

## Невыполненные проверки (обязательны перед production)

- Live Moodle Mustache runtime, Moodle PHPUnit/Behat, KBA Theme + Boost, real task CRUD/ACL, live user company isolation и браузер на сервере edu.kzn.mba.
- В частности, подтвердить работу `create`, `edit`, `classifyduty`, `start`, `submit`, `checkset`, `attachmentadd`, `discussionpost`, шаблоны `owned` и возврат к legacy.

## Изменения по файлам относительно 1641

- `+ bms_ui/task_center.css`
- `+ templates/bms24_task_header.mustache`
- `+ templates/bms24_task_panel.mustache`
- `+ templates/bms24_task_tabs.mustache`
- `+ templates/bms24_task_toolbar.mustache`
- `+ tests/bms24_task_owned_presentation_test.php`
- `~ classes/local/bms24_shell_service.php`
- `~ classes/local/bms24_ui_kit_service.php`
- `~ classes/local/task_ui_service.php`
- `~ db/upgrade.php`
- `~ tasks.php`
- `~ templates/bms24_ui_field.mustache`
- `~ version.php`
