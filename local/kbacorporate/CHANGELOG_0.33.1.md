# CHANGELOG — v0.33.1

## Added

- `implementation_evidence.php` — рабочее пространство доказательств внедрения.
- `classes/local/implementation_evidence.php` — версия, отправка, проверка и журнал решений.
- `classes/form/implementation_evidence_submission_form.php` — форма отправки материалов.
- Таблица `local_kbacorp_impl_evid` для неизменяемых версий доказательства.
- Таблица `local_kbacorp_impl_evlog` для audit trail.
- Filearea `implementation_evidence_record`.
- PHPUnit regression source `tests/implementation_evidence_test.php`.
- Privacy metadata/export/delete support and Health Center table checks.

## Changed

- Принятие/возврат версии управляет статусом задачи атомарно.
- После первой версии статус задачи меняется только через управляемый workflow.
- Старая форма результата скрыта на обновлённой системе.
- Последняя версия сохраняется также в legacy-полях задачи для совместимых интеграций.
- Старые доказательства отображаются как read-only legacy data.

## Governance

- Исполнитель не может сам проверить своё доказательство.
- Возврат требует комментария.
- Повторная отправка создаёт новую версию и не переписывает старую.
- Одновременная дублирующая отправка блокируется.
- Межкорпоративный доступ к данным и файлам не допускается.
- Изменение схемы только аддитивное; destructive migration отсутствует.
