# Test Report — 0.23.3.1

Build: `2026090258`

Local gate covers XML parsing, RU/EN language parity and duplicate keys, PHP structural checks, version consistency, conflict markers and ZIP integrity. Browser acceptance and `php -l` remain required on the target Moodle server because PHP is unavailable in the build environment.

Acceptance checklist:

1. While viewing company 3, a multi-company administrator clicks the permanent “BMS implementation” shortcut and reaches the client registry without `companyid=3`.
2. Selecting another company opens the correct isolated workspace.
3. “Select another company” inside a company workspace returns to the registry.
4. A manager with exactly one company opens that company directly.
5. Search, filters, sorting, pagination and CSV from v0.23.3 remain operational.
