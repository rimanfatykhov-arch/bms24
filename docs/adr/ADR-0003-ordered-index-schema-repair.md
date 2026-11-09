# ADR-0003 — ordered task index and strict XMLDB repair

Date:2026-11-09. Status: implemented in isolated review branch at the owner's
request; not a merge, RC30 promotion or deployment approval. Builds on ADR-0002.

PR49's real Moodle matrix demonstrated that Moodle4.1 `index_exists()` compares
column sets, not order or uniqueness. The old due_ix therefore hid the missing
status-first index after1645 upgrade. The0890 helper also passed empty-string
defaults to XMLDB, which emitted warnings even though SQL DDL succeeded.

Decision:

- Keep the historical0890 checkpoint reachable and correct its XMLDB constructor
  default to `null` for empty time values. Continue validating actual SQL defaults
  as empty strings; do not rewrite business values.
- Add checkpoint2026110900. Revalidate the three fields, then ensure the ordered
  nonunique task index `(companyuuid,status,duedate)`. This also repairs sites
  already saved at0890. Savepoint follows verified completion only.
- Preserve the existing due_ix and every other index; no DROP, rename, data UPDATE
  or uniqueness conversion. Existing exact ordered nonunique indexes satisfy the
  requirement independent of their name.
- For a missing index on the requested MySQL-family profiles, use the public
  Moodle driver generator and DML DDL execution API. `add_index()` cannot be used
  because it internally repeats the incorrect set-based existence check.
- Give the repair a deterministic validated physical identifier derived from the
  Moodle prefix. Reject a conflicting definition under that name. Replace only
  the generated index identifier; retain driver quoting of table/column names.
  Require a single expected CREATE INDEX statement; reject unexpected output.
- Re-read uncached metadata after DDL, verify exact order/nonuniqueness and that
  all old definitions are unchanged. Partial completion is retryable; no forced
  rollback of MySQL implicit-commit DDL is claimed.
- Read-only acceptance checks use ordered metadata and uniqueness for all four
  required indexes. Do not waive runtime-profile warnings or unrelated failures.

Limit: when an index is missing, other database families fail closed pending a
separately verified DDL path. Moodle core is unchanged. No cross-tenant data is
read or modified by this helper. Index creation can lock/consume resources in a
real deployment; this task executes it only in disposable CI databases.

Verification: real fresh installation, historical1645/1879/0890 upgrades, exact
183-table schema comparison, two-tenant preservation/authorization, focused
warning-free repair/collision/idempotence tests and complete plugin suites on
MariaDB10.11.19 and Percona8.0.46-37. Results are reported separately from this
decision; known unrelated PR49 failures must keep overall acceptance on HOLD.
