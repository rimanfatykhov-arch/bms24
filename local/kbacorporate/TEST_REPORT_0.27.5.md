# Static test report — 0.27.5

- Version, XMLDB marker and upgrade savepoint match build `2026090283`.
- The release contains no schema changes.
- The commercial-stage panel has stable package and request anchors.
- Package/request links no longer point to `implementation_plan.php`.
- The plan endpoint redirects requests without a program to package selection.
- The plan endpoint redirects unpaid or inactive requests to request management/status.
- Only eligible paid programs expose the managed plan.
- Audit/program cards use context-aware package, request or task actions.
- Russian and English language keys have exact parity.

PHP CLI and a Moodle test database are not available locally; runtime browser verification remains required on the target Moodle server.
