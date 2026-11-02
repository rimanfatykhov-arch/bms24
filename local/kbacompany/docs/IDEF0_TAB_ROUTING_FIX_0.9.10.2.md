# IDEF0 Tab Routing Fix — 0.9.10.2

## Symptom
Clicking the IDEF0 tab produced a URL with `tab=idef0`, but the Process card stayed on Passport. The edit route had the same hidden defect.

## Root cause
Both routes used Moodle `PARAM_ALPHA`. Because `idef0` contains the digit `0`, sanitization reduced it to `idef`. The subsequent explicit allowlist correctly rejected that value and fell back to the default tab.

## Fix
- `processes.php`: `optional_param('tab','passport',PARAM_ALPHANUMEXT)`
- `process_design.php`: `optional_param('tab','sipoc',PARAM_ALPHANUMEXT)`
- Existing strict tab allowlists are retained unchanged.

## Scope guard
No other tab routes are changed because their current values are alphabetic. No business logic, data model, schema, permissions or IDEF0 semantics are changed.
