# Test report — 0.18.0

Build: `2026090209`

## Local static gate

- 110 PHP files passed the structural delimiter/conflict-marker scan.
- All XML files parsed successfully.
- RU/EN language files are aligned: 1044 keys in each language.
- Build/release/savepoint consistency passed for `2026090209` / `0.18.0`.
- Full ZIP root and manifest were validated after packaging.

## Added automated coverage

- `tests/competency_map_test.php`: “not assessed” differs from level 0; summary counts unique employees and requirements.

## Required on Moodle server

- Run `php -l` for every PHP file and PHPUnit.
- Upgrade a copy of production from build `2026090208` to `2026090209`.
- Verify two-company isolation and department-manager restriction.
- Verify values match the same employee in “My competencies”.
- Verify filters and links in Russian and English.
