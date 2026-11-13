# Kanban fixture stabilization after PR60

Base: exact PR60 a60ce4e99fe1931774eaa7da3be0e23fe1d0b92f. Branch fix/rc30-kanban-fixtures.

The recent unchanged-runtime PR60 raw JUnit from both engines independently confirms two invalid_parameter_exception errors in task_kanban1846/1850 rendering tests. See PR62_KANBAN_BASELINE.json for exact identities/stacks and immutable artifact IDs. No redundant rerun of that unchanged baseline is claimed.

Both fixtures used test-company rather than a canonical UUID and fictional actor10. task_ui_service::listing calls task_service::record_actions, which resolves company ACL through access_role_service. The guard is correct. Seed a native company and actual ordinary member/session; set model.actor, and in1850 assignee/effective-assignee/creator, to that user. Assert no site-admin, viewallcompanies or company-manager privilege. Keep can_manage=false and all original assertions. Add positive escaped content, exact card cardinality, hidden column, full count5 versus rendered1, WIP5/1 and allowed start/no accept checks.

Only tests and CI trigger/focused coverage change. Runtime/plugin version, Corporate, authorization, schemas, migrations, UI implementation and business rules remain unchanged. BMS2026110906 / Corporate2026110901 retained deliberately: this is a test-only follow-up, not a new product build. Git SHA identifies the candidate. Packaged runtime must remain identical to the PR60 artifact. The existing packager includes PHP tests, so the ZIP hash will change for exactly these two test files; this artifact is identified by run/SHA/hash and must not be published as a new build0906 release.

All prior acceptance scenarios and suites remain enabled. Add the two focused suites; no skipped/removed/weakened checks. These render DTO tests do not replace SQL/HTTP authorization acceptance. Prior service-level company isolation and membership tests remain mandatory. RC30 HOLD pending complete acceptance; no staging/production changes or merge.

Final dual-engine results: both target errors resolved; Kanban10/90 PASS, full BMS618/7274 with19 retained failures and0 skips, Corporate150/962 PASS. See [PR62 acceptance](PR62_ACCEPTANCE.md). Source rollback is to abandon/revert this test-only branch to exact PR60. No installed version or database rollback is needed because no deployment or migration occurs.
