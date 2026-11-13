# Independent review and acceptance boundaries

Reviewed source:80f454bf01a3a925ca302fb3e46cf64b4585386d against PR56
1ced6b88e5933bb8d63aa1806411ac4d8a6a5443 on2026-11-09.

Security reviewer reported scoped no-new-finding:83 non-overlap selected source
paths match parent provenance (excluding two explicitly annotated metadata files),
no deleted files, Corporate unchanged, no new CI write/deployment permissions,
and no obvious private-key/token signatures in the limited scan. This is not
certification that the entire legacy application is free of vulnerabilities.

QA reviewer found no concrete new fixture defect in source inspection and
prepared an independent comparison of JUnit leaf identities/counts with reports
and both parents. Runtime findings take precedence over that preliminary review.

The new access tests cover canonical membership revocation/inactivation without
a new session, target/membership identifiers and cross-company document/revision
boundaries. The document negative test deliberately uses an administrator to
isolate object/company validation. It does not prove the complete non-admin HTTP
ACL, browser request/session behavior, legacy membership fallback or archived
company policy. These remain separate acceptance work; no policy was loosened.

Architecture review permits preserving the existing capability-scoped Moodle
review queue alongside UUID-scoped BMS HR projections. BMS management membership
does not confer Moodle grading rights. Tightening the queue into a company-only
registry or broadening its permissions would require a separate architecture
decision; neither change is included here.

Live stage41 inspection was restricted to version text and file hashes without
loading Moodle. The installed source declares Corporate2026102613. Its full
source export was rejected by automatic approval review as beyond this audit's
authorized private-source export scope. The rejected archive was not downloaded;
the permitted narrower hash inventory was used instead. Hash differences prove
different trees, not the semantics of the additional board/paging/diagnostic code.
The installed DB version was not newly queried. Production was not accessed.

The matrix's MariaDB10.11/Percona8.0 results do not silently replace the historical
strict MySQL5.7 runtime profile in RC30 policy. Version/diagnostic tests preserve
strict warnings and fail-closed behavior. Runtime-profile adoption, browser/UI,
full sibling-plugin stack, live Corporate2613 reconciliation and backup/restore
still require their own evidence before deployment acceptance.
