# Test Report — 0.23.3

Build: `2026090257`

Local gate covers XML parsing, RU/EN language parity and duplicate keys, PHP structural checks, version consistency, conflict markers and ZIP integrity. Browser acceptance and `php -l` remain required on the target Moodle server because PHP is unavailable in the build environment.

Acceptance checklist:

1. The registry sorts by latest activity, company name in both directions, and implementation state.
2. Page sizes 12, 24 and 48 retain the active search, state and sort parameters.
3. Pagination never exposes a company outside the current user's allowed scope.
4. Latest audit date and BMS Index match the company's latest audit.
5. An active paid program is preferred as the current program; otherwise the latest request/program is shown.
6. Package, commercial stage, curator and latest activity are rendered correctly.
7. A company without an audit receives “Create audit”; an audited company without a request receives “Offer package”.
8. A company with a program receives “Open program” and authorized administrators reach the editor.
9. The program editor saves and preserves the selected authorized curator.
10. CSV contains every filtered company, not only the current page, with all documented columns.
11. Reset clears search, state, sorting and page-size changes.
12. Client cards and controls remain usable on mobile without page-level horizontal scrolling.
