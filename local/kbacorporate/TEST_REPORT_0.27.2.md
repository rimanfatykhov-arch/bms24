# Static test report — 0.27.2

- Version, XMLDB marker and upgrade savepoint match build `2026090280`.
- Tool catalogue queries include only the selected company and global templates.
- Current-company audit and criterion counters are covered by PHPUnit scenarios.
- Global templates are blocked from company-context criterion editing.
- Tool and criterion codes are checked for duplicates inside their proper scope.
- Attention threshold cannot exceed the selected scale, and criterion weight must be positive.
- Existing audit snapshots remain unchanged when future tool settings or criteria are edited.
- Russian and English language keys and Moodle 4.0 link attributes pass static validation.

PHP CLI and a Moodle test database are not available locally; runtime verification remains required on the target Moodle server.
