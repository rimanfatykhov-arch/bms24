# Test Report — 0.23.2

Build: `2026090256`

Local gate covers XML parsing, RU/EN language parity and duplicates, PHP structural checks, version consistency, conflict markers and ZIP integrity. Browser acceptance and `php -l` remain required on the target Moodle server because PHP is unavailable in the build environment.

Acceptance checklist:

1. A multi-company administrator sees search and state controls above the client cards.
2. Name search is case-insensitive and retains the entered value after submission.
3. “No audit” returns companies without implementation audits.
4. “Audit completed, no request” returns companies that can be offered a package.
5. “Request or program exists” includes active and non-active programs.
6. “Active program” only returns paid programs with active delivery status.
7. Reset clears both search and state filters.
8. Every visible card shows the correct status, audit count and request/program count.
9. No statistics or cards are returned for companies outside the viewer's allowed scope.
10. Filter controls stack cleanly on mobile without horizontal page scrolling.
