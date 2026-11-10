# build2026111002 — non-manager workspace state boundary

Unaccepted RC30 candidate; release label remains1.1.0-rc29. No deployment or merge.

Normalize a non-manager's requested manager display to list and company-wide scope
to personal roles before the renderer creates links/forms. The existing route already
enforces this fallback. Preserve the late renderer guard and existing service SQL/ACL.
No new rights, company membership semantics, database definitions or schema-changing migrations.

Real-service regression cases cover two companies, explicit task-edit grants,
same-session revocation of grants/assignments/roles and inactive/removed membership.
Original workspace assertions remain; owned and legacy rendering get negative tests.
Pinned PR67 red reproduction precedes restored-candidate focused/full PHPUnit in CI.

Version1002 distinguishes this runtime fix from PR67 build1001. Historical migration
checkpoints are unchanged;1001 remains the terminal schema-changing checkpoint. A
version-only1002 savepoint records this code update without DDL. Actual Moodle
upgrade from PR67/1001 and older versions is required before acceptance.
