# BMS2026110905 — bounded RC30 P0 regression stabilization

Base PR58 d3a16c3; Corporate2026110901 unchanged. Eight failures reproduced on
unchanged baseline before fixture edits. Observation/acceptance dates now share
one timestamp and use the existing ISO business-date helper. Overdue task fixtures
use a valid optional start and the employee's yesterday. Battle Plan fixtures
explicitly submit nonempty plans and obtain reviewer acceptance for all work
before closing; inherited reviewer, approval metadata and canonical task identity
are preserved.

Added strict rejection coverage for invalid period/date/time order, observation
before task acceptance, empty submission, premature closure and unauthorized
acceptance. No business rule, Corporate authorization or Moodle Core change.
No UI, finance, AI Studio or SaaS changes.0905 is a code-only version checkpoint
identifying the test-bearing artifact; no DDL/data migration. Full focused and
BMS/Corporate matrix required, including exact0904 upgrade. RC30 stays HOLD until
full acceptance. No merge or staging/production deployment.
