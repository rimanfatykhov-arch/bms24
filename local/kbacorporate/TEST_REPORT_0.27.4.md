# Static test report — 0.27.4

- Version, XMLDB marker and upgrade savepoint match build `2026090282`.
- The release contains no schema changes.
- The shared navigation helper builds all URLs through Moodle URL objects.
- Company-specific menu entries require a validated positive company context.
- The active item receives both a visual state and `aria-current="page"`.
- The menu carries an accessible navigation label.
- Main implementation, curator, board, analytics, audit-list and tool-library pages use the shared component.
- Russian and English language keys have exact parity.
- Mobile styles use component-level horizontal scrolling and do not force page-level overflow.

PHP CLI and a Moodle test database are not available locally; runtime browser verification remains required on the target Moodle server.
