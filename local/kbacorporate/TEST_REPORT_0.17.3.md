# Test Report — KBA Corporate Dashboard 0.17.3

## Local release gate

- Version/build/install schema consistency: checked.
- XML well-formedness: checked.
- English/Russian language-key parity: checked.
- Conflict markers and archive-root checks: checked.
- Static PHP structure and named SQL placeholder checks: checked.
- ZIP integrity and SHA256: performed during packaging.

## Added regression coverage

- Role audit creates target-profile items.
- Missing evidence remains **Needs assessment** and is not treated as level 0.
- Manual evaluation updates the existing missing item without duplication.
- Existing Moodle competency grade is collected as confirmed evidence.
- Structured evidence is removed before audit items during company deletion.
- Privacy metadata includes structured audit evidence.

## Required on target Moodle

- `php -l` for all PHP files.
- Moodle PHPUnit suites, including `competency_audits_test.php`.
- Upgrade from installed build `2026090104`.
- Runtime acceptance using real role profiles, competency scales and company courses.

These target-stack checks cannot be claimed by the local environment because it does not include PHP or a running Moodle database.
