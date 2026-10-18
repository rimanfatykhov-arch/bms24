# Test Report — 0.21.3.5

Build: `2026090247`

Local structural gates cover XMLDB/build consistency, language parity and ZIP integrity.

Target Moodle acceptance:

1. Open any BMS criterion through `Оценить`.
2. Confirm there is no `[[comment]]` placeholder.
3. Confirm the maturity-level help text is available.
4. Save with an uploaded file only, and separately with an external link only.
5. Confirm verification still requires at least one supporting material.

PHP lint, PHPUnit and runtime acceptance remain pending on the target Moodle stack.
