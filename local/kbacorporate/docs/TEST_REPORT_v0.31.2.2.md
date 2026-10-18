# TEST REPORT — v0.31.2.2

## Static release gate
- Moodle ZIP root: `kbacorporate/` only.
- Numeric build: `2026090610`.
- Release: `0.31.2.2 — Selective Role Profile Migration`.
- PHP syntax check: PASS — 196/196 PHP files.
- XML validation: PASS — `db/install.xml` parses and is unchanged vs v0.31.2.1.
- ZIP integrity: PASS after packaging.
- SHA-256: generated after packaging.

## Functional checks for pilot Moodle
1. Open a role containing BMS-D00/BMS-D01 and open Role Profile Integrity.
2. Verify no child competency is selected by default.
3. Select a subset from one or both domains and set different target levels/priorities/mandatory flags.
4. Apply selective migration.
5. Verify only selected missing competencies are added.
6. Verify existing concrete requirements are not overwritten.
7. Verify a checked domain is removed only when a concrete direct child remains after the operation.
8. Verify unselected domains remain when no concrete child exists.
9. Re-open Role Learning Matrix and GAP builder; verify only concrete requirements participate.

## Status
Static checks are performed during package build. Runtime Moodle regression remains to be confirmed in the pilot environment.
