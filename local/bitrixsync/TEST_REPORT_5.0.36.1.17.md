# TEST REPORT — KBA Connect v5.0.36.1.17

Static/package QA performed against the exact v5.0.36.1.16 installable baseline.

- PHP lint: 413/413 PASS.
- RU/EN language parity: 435/435, duplicate keys 0.
- Literal `get_string(..., local_bitrixsync)`: 285 calls, missing keys 0.
- PHP 7.3 scan of changed PHP files: 0 post-7.3 syntax/API candidates.
- `db/install.xml`: well formed, 7 table definitions, duplicate table definitions 0; byte-identical to v5.0.36.1.16 baseline.
- `db/access.php`: byte-identical to v5.0.36.1.16.
- `db/tasks.php`: byte-identical to v5.0.36.1.16.
- `db/services.php`: absent in baseline and release.
- Privacy provider: absent in baseline and release.
- Upgrade 2026083627: savepoint only; DDL/data writes 0.
- New diagnostic DB writes: 0.
- New diagnostic `set_config`/`unset_config`: 0.
- New diagnostic Bitrix write methods: 0.
- New diagnostic direct `local_kbacorp_*` writes: 0.

Not run: Moodle PHPUnit, browser E2E, production upgrade, live diagnostic lookup against production Company 385.
