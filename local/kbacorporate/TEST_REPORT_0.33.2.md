# Test Report v0.33.2

## Scope

Статическая и упаковочная проверка интеграции действий внедрения с Канбаном.

## Environment

- Source baseline: `v0.33.1`
- Target build: `2026090812`
- Minimum supported Moodle: 4.0
- Runtime Moodle instance: not available in build container

## Static release gate

| Test | Expected | Result |
|---|---|---|
| PHP syntax for all plugin PHP files | no syntax errors | PASS |
| `db/install.xml` XML parse | valid | PASS |
| install/upgrade field parity | `eventtype`, `detailsjson` in both paths | PASS |
| version metadata | build `2026090812`, release `0.33.2` | PASS |
| RU/EN language key parity | identical key sets | PASS |
| duplicate language keys | none | PASS |
| ZIP root | only `kbacorporate/` | PASS |
| ZIP integrity | no corrupt entry | PASS |
| destructive migration | absent | PASS |

## Code-level scenarios covered by PHPUnit test

`tests/implementation_kanban_test.php` defines scenarios for:

1. authorised manager changes owner, due date and priority without changing task status;
2. the change is persisted as a `kanban` history event with before/after JSON;
3. idempotent re-save creates no history row;
4. an owner from another company is rejected;
5. a completed card cannot be edited;
6. an invalid calendar date is rejected.

The PHPUnit suite is included but was not executed because a configured Moodle PHPUnit environment is not available in the build container.

## Required pilot runtime tests

1. Upgrade a database copy from `2026090811` to `2026090812`.
2. Confirm old tasklog rows receive `eventtype=status` and remain readable.
3. Open the board as curator, company manager and ordinary employee; verify scope.
4. Filter by company and audit; confirm the audit shortcut retains the correct company context.
5. Change owner, due date and priority; verify task page, board and history agree.
6. Re-save without changes; verify no duplicate history event.
7. Start a planned task as its owner and confirm movement to «В работе».
8. Submit, reject and accept evidence; confirm movement through «На проверке», «В работе» and «Выполнено» only via workflow.
9. Verify notification delivery or logged messaging failure according to site configuration.
10. Verify privacy export/deletion and company cleanup after schema migration.

## Verdict

Automated static release gate passed: 236/236 PHP files, XML/build parity, RU/EN parity (2727/2727 keys), no duplicate language keys and no missing literal plugin strings. Prepared for pilot runtime validation. Not declared Stable until runtime checks are recorded.
