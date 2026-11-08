# BMS24 build2026101878 — GitHub review artifact gate

- Plugin version: 2026101878, release string 1.1.0-rc29.
- No DB schema, permission, or business behavior changes.
- GitHub Actions prepares deterministic INSTALL ZIP for pull requests before any merge.
- Workflow policy rejects deploy commands, secrets, write permissions and unreviewed triggers.
- Candidate artifacts require human review plus separate real Moodle staging acceptance.
- The existing battleplan_date and date input regressions remain mandatory.
- Automated GitHub import is fail-closed, version-specific, and never force-pushes main.
