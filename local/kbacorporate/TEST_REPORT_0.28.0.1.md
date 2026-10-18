# Static test report — 0.28.0.1

- Version, XMLDB marker and final upgrade savepoint match build `2026090287`.
- Foreign-key/index collision scan passes for the new BMS competency table.
- The redundant single-column index was removed; the foreign-key index and composite unique index remain.
- `db/install.xml` parses successfully and archive-root validation is required before delivery.
- PHP CLI and a Moodle test database are unavailable locally; target-server PHP lint, PHPUnit and upgrade acceptance remain required.
