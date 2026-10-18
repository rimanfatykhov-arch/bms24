# Test Report — 0.21.3.4

Build: `2026090246`

## Local gate

- XMLDB, build and upgrade consistency.
- RU/EN language parity and uniqueness.
- PHP structural scan and ZIP integrity.

## Required Moodle acceptance

1. Upgrade from 0.21.3.3 and verify both new company fields.
2. Edit a company, save a niche and founding year, and reopen the form.
3. Verify the values in the company register and corporate dashboard.
4. Verify rejection of years before 1800 and after the current year.
5. Verify additive ERP push and snapshot fields without breaking older clients.

PHP lint, PHPUnit and runtime acceptance remain pending on the target Moodle stack.
