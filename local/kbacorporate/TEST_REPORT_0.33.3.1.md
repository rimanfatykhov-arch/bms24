# Test Report v0.33.3.1

## Scope

Статическая, миграционная и упаковочная проверка нейтральной терминологии ERP в пользовательском интерфейсе.

## Environment

- Source baseline: `v0.33.3 / 2026090813`
- Target build: `2026090814`
- Minimum supported Moodle: 4.0
- Runtime Moodle instance: not available in build container

## Static release gate

| Test | Expected | Result |
|---|---|---|
| PHP syntax for all plugin PHP files | no syntax errors | PASS |
| `db/install.xml` XML parse | valid | PASS |
| install.xml unchanged from v0.33.3 | identical SHA-256 | PASS |
| version metadata | build `2026090814`, release `0.33.3.1` | PASS |
| upgrade path | savepoint `2026090814` | PASS |
| RU/EN language key parity | identical key sets | PASS |
| duplicate language keys | none | PASS |
| user-facing runtime surfaces contain `Odoo` | no occurrences | PASS |
| internal source value remains `odoo` | preserved | PASS |
| form source label uses language string | `sourceodoo` → `ERP` | PASS |
| ZIP root | only `kbacorporate/` | PASS |
| ZIP integrity | no corrupt entry | PASS |
| destructive migration | absent | PASS |

## Migration checks

The upgrade step is restricted to Moodle competency templates and plans that contain KBA markers:

- `KBA-BUSROLE:`
- `KBA-DEVPLAN:`

Only the description field is modified. The replacement is idempotent: after the first successful upgrade no matching `Odoo` text remains, and subsequent runs do not change the records.

## Required pilot runtime tests

1. Upgrade a database copy from `2026090813` to `2026090814`.
2. Confirm the API diagnostics page displays `ERP` and no `Odoo` label.
3. Confirm the economic source selector displays `ERP` while an existing record with source value `odoo` remains readable.
4. Confirm the Learning Plans integration page displays ERP terminology.
5. Confirm descriptions of KBA-created Moodle templates and plans are updated.
6. Confirm non-KBA template and plan descriptions are unchanged.
7. Call the existing ERP API using the provider token `odoo` and confirm backward compatibility.
8. Purge Moodle caches only if an old translated label remains visible after upgrade.

## Verdict

Prepared for pilot runtime validation. No database schema change is introduced.
