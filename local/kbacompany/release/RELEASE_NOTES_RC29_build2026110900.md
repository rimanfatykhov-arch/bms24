# RC29 build2026110900 — schema acceptance repairs

Candidate only; no RC30 promotion, merge or deployment authorization.

- Correct XMLDB representation of empty time-field defaults in the0890 helper;
  SQL defaults and existing values remain unchanged.
- Add a reachable0900 checkpoint for the ordered nonunique task index
  `(companyuuid,status,duedate)`, preserving the existing due-date-first index.
- Validate exact column order and uniqueness in the read-only acceptance gate.
- Repeat isolated Moodle installations/upgrades from1645,1879 and0890 on
  MariaDB10.11.19 and Percona8.0.46-37, with full plugin PHPUnit diagnostics.

Known unrelated communication, Corporate ERP/privacy and test-suite blockers
from PR49 are not silently waived. Full acceptance remains HOLD until verified.
