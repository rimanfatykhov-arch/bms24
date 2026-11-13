# RC29 build2026110902 — RC30 diagnostic compatibility

Isolated source candidate on PR52; no merge/deployment or RC30 promotion.

- Classify the missing-schema hint from Moodle's typed coding_exception payload,
  independent of localized rendered messages. Return only a fixed safe error code.
- Regression coverage distinguishes canonical errors from warnings and preserves
  strict-mode escalation; report inputs cannot change canonical severity.
- Preserve exact expected/installed version matching, including rejection of an
  unaccepted future build. Corporate remains2026110901/API1.26 unchanged.
- New BMS code-only savepoint2026110902; no schema/ACL/business-data migration.

Full release acceptance remains HOLD pending the recorded Moodle matrix and the
other outstanding Issue44 blockers. No claim of staging or production acceptance.
