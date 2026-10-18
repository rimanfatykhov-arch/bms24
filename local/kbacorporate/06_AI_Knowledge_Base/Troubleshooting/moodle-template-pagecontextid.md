# Moodle template link: missing pagecontextid

## Симптом

При переходе из KBA в стандартный шаблон учебного плана Moodle появляется `missingparam`: отсутствует `pagecontextid`.

## Диагностика

Проверить ссылку. Некорректный вариант:

```text
/admin/tool/lp/templatecompetencies.php?templateid=4
```

Корректный контракт:

```text
/admin/tool/lp/templatecompetencies.php?templateid=4&pagecontextid=<contextid>
```

## Root cause

`tool_lp/templatecompetencies.php` использует `pagecontextid` для восстановления контекста страницы и навигации.

## Fix

Использовать `local_kbacorporate\local\moodle_learning_plans::template_url()`, который начиная с v0.32.2.1 определяет контекст шаблона и включает оба параметра.
