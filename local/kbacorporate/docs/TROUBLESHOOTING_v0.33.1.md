# TROUBLESHOOTING — Implementation evidence v0.33.1

## «Хранилище доказательств ещё не установлено»

Complete Moodle database upgrade, purge caches and verify build `2026090811`. Integration Health Center must show both evidence tables.

## Исполнитель не видит отправку

Check that:

- the user is the current task owner;
- the user is an active employee of the company;
- task status is «В работе»;
- another version is not already pending review.

## Проверяющий не видит кнопки решения

Check that the task is «На проверке», the latest version is `submitted`, and the user is a programme curator or authorised company manager. The task owner cannot self-review.

## Возврат не выполняется

A non-empty reviewer comment is required.

## Файл не открывается

Confirm that the file belongs to the version shown, the user still has task access, and the pluginfile URL uses filearea `implementation_evidence_record`.

## Статус задачи и доказательства расходятся

Do not edit the task status directly in the database. Verify the latest evidence status and task history, restore from backup if an external/manual database change caused the mismatch.

## Legacy evidence is not in version history

This is expected. Pre-v0.33.1 material is shown separately as read-only legacy evidence and is not assigned a fabricated version or review decision.
