# TEST REPORT — KBA Connect v5.0.36.1.16

Static/package QA performed against the server-provided v5.0.36.1.15 tree.

- PHP lint: 410/410 PASS.
- RU/EN language parity: 389/389, duplicate keys 0.
- Literal `get_string(..., local_bitrixsync)`: 231 calls, missing keys 0.
- `db/install.xml`: well formed and byte-identical to v5.0.36.1.15.
- `db/access.php`: byte-identical to v5.0.36.1.15.
- `db/services.php`: absent in both baseline and release.
- Privacy provider: absent in both baseline and release.
- Upgrade 2026083626: savepoint only; no DDL.
- New BMS bridge direct `local_kbacorp_*` table writes: 0.
- Existing Deal enrolment services were not modified.

Not run: Moodle PHPUnit, browser E2E, production upgrade, live Bitrix REST writes, live KBA Corporate membership reconciliation.
