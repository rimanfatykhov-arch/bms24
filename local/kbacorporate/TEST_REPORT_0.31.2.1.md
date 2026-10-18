# TEST REPORT — v0.31.2.1

**Component:** `local_kbacorporate`  
**Build:** `2026090609`

## Static release gate

- PHP syntax check: PASS — 196 PHP files, `php -l`.
- Correct ZIP root `kbacorporate/`: PASS in pre-package validation; final artifact revalidated after documentation finalisation.
- ZIP integrity: PASS in pre-package validation; final artifact revalidated after documentation finalisation.
- `lang/en` and `lang/ru`: present.
- `db/install.xml`: PASS — XML parsed successfully and byte-identical to v0.31.2.
- `db/upgrade.php`: savepoint `2026090609`, no schema changes.
- Destructive migration on upgrade: none.

## Functional checks to perform in Moodle pilot

1. Existing role with `BMS-D00`/`BMS-D01` shows warning and integrity page.
2. `BMS-Dxx` does not appear in Add competency autocomplete.
3. Attempt to save a domain node through service is rejected.
4. Matrix excludes domain nodes and no longer displays `[[competency]]`.
5. GAP builder button displays `Preview`/`Предпросмотр`, not `[[preview]]`.
6. Expansion preview shows direct child competencies.
7. Explicit expansion preserves target level/priority/mandatory flag and existing concrete requirements.
8. After expansion, domain count is 0 and concrete requirements are present.
9. GAP calculation and Moodle Learning Plan preview use only concrete competencies.
10. Reopening integrity page after expansion is idempotent and proposes no further destructive action.

## Status

Static code/XML checks PASS. Final ZIP root/integrity/SHA256 are validated after packaging. PHPUnit/Moodle runtime tests are not available in this build container; the Moodle pilot steps above remain required before declaring Stable.
