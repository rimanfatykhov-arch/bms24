# Test Report — v0.32.2.1

## Scope

PATCH-проверка исправления URL стандартного шаблона учебного плана Moodle.

## Static checks

- корень ZIP: `kbacorporate/`;
- `version.php`: build `2026090808`;
- `db/upgrade.php`: savepoint `2026090808`;
- обязательный параметр `pagecontextid` присутствует в `template_url()`;
- прямых альтернативных ссылок на `templatecompetencies.php` без helper не найдено;
- PHP syntax check — 223/223 файлов без ошибок;
- `db/install.xml` — валиден и не изменён;
- ZIP integrity — проверена;
- SHA-256 — сформирован.

## Automated regression test added

`tests/moodle_learning_plan_urls_test.php`

Проверяется:

1. явная передача `pagecontextid`;
2. автоматическое чтение контекста из Moodle template;
3. наличие одновременно `templateid` и `pagecontextid` в URL.

## Runtime scenario for pilot

1. Открыть интеграцию учебных планов компании.
2. Нажать «Открыть шаблон Moodle #4».
3. Ожидаемый URL: `templatecompetencies.php?templateid=4&pagecontextid=<context>`.
4. Ожидаемый результат: страница шаблона открывается без `missingparam`.

## Database impact

Нет изменений схемы, шаблонов, компетенций или планов.

## Status

Локальный release gate пройден. PHPUnit-тест добавлен, но его выполнение вместе с полной runtime-проверкой выполняется на пилотном Moodle 4.0.
