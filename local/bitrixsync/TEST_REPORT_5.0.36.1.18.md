# TEST REPORT — KBA Connect v5.0.36.1.18

Static/package QA performed against the exact v5.0.36.1.17 installable baseline.

- PHP lint: 413/413 PASS.
- RU/EN language parity: 442/442, duplicate keys 0.
- Literal `get_string(..., local_bitrixsync)`: 295 calls, missing keys 0.
- PHP 7.3 scan of changed PHP files: 0 confirmed post-7.3 syntax/API candidates (regex `enum` hits were ordinary words/comments such as `enum mapping`).
- `db/install.xml`: well formed, 7 table definitions, duplicate table definitions 0; byte-identical to v5.0.36.1.17 baseline.
- `db/access.php`: byte-identical to v5.0.36.1.17.
- `db/tasks.php`: byte-identical to v5.0.36.1.17.
- `db/services.php`: absent in baseline and release.
- Privacy provider: absent in baseline and release.
- Upgrade 2026083628: savepoint only; DDL/data writes 0.
- Diagnostic DB writes: 0.
- Diagnostic Bitrix write methods: 0.
- Removed files: 0.

Not run: Moodle PHPUnit, browser E2E, production upgrade, live selector rendering against production Bitrix24, live Company 385 synchronization.
