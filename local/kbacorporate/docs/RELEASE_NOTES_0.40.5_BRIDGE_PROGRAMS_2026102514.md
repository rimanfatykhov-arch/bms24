# Corporate 0.40.5 API 1.23 — read-only implementation programs

Adds `bridge::list_implementation_program_projections(companyid, actorid, limit, offset)` and
`bridge::get_implementation_program_projection(companyid, actorid, programid)`.
Only paid, active/paused/completed programs for a fully company-authorized implementation manager.
Returns safe project metadata and grouped counts from nondeleted tasks. No cross-plugin writes or schema changes.
Requires Moodle server acceptance to verify ACL and query correctness against a real database.
