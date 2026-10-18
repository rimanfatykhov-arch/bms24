# KBA Corporate Dashboard 0.20.0.1 — Test Report

Build: `2026090238`

## Local release gate

- Confirmed that `index.php` no longer creates a `Мои компетенции` navigation card.
- Confirmed that `lib.php` still exposes the permanent navbar entry.
- Confirmed that `mycompetencies.php` remains present and unchanged.
- Confirmed version, install XML and upgrade savepoint alignment.
- Confirmed ZIP root and archive integrity.

## Required Moodle acceptance

1. Upgrade from `0.20.0` to `0.20.0.1`.
2. Open the corporate customer dashboard and confirm the duplicate card is absent.
3. Use the top navbar `Мои компетенции` button and confirm the personal page opens.
4. Confirm company competency map, roles, audits, plans and effectiveness cards still open normally.
