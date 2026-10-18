# Static test report — 0.27.6

- Version, XMLDB marker and upgrade savepoint match build `2026090284`.
- The release contains no schema changes.
- Personal navigation is always present and My BMS tasks requires an assigned task.
- Corporate navigation requires both dashboard capability and an actual company/department manager membership, or site administrator status.
- Curator navigation requires a curator assignment or implementation-management capability.
- Client, audit and tool navigation requires implementation-management capability.
- Personal and corporate groups are rendered before implementation-management controls.
- The permanent header follows the same personal, company and implementation ordering.
- Core implementation detail pages and BMS tool editors use the shared component.
- Russian and English language keys have exact parity.
- Horizontal group scrolling prevents page-level overflow on narrow screens.

PHP CLI and a Moodle test database are not available locally; runtime browser verification remains required on the target Moodle server.
