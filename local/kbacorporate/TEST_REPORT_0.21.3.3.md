# Test Report — 0.21.3.3

Build: `2026090245`

## Local gate

- XMLDB well-formedness and build consistency.
- RU/EN language-key parity and uniqueness.
- Structural scan of all PHP files and conflict markers.
- ZIP root and integrity validation.
- Unit coverage supplied for decimal parsing and derived economic ratios.

## Required Moodle acceptance

1. Upgrade from 0.21.3.2 and verify `local_kbacorp_impl_econ` creation.
2. Open a draft audit and add economic indicators with comma decimals.
3. Upload a supporting PDF or spreadsheet and reopen it.
4. Verify automatic ratios and negative operating/net profit support.
5. Confirm the audit and verify that its economic snapshot becomes immutable.
6. Create a later audit and verify Point A / Point B comparison.

PHP lint, PHPUnit and runtime acceptance remain pending on the target Moodle stack.
