# Stage41 functional line: 2026110804 review

This draft preserves the stage41 functional line; it is not the RC30 integration baseline.
Review base is the existing local commit `0bda9d6` (review queue and native company fixtures), published unchanged as `snapshot/stage41-before-0801`. It descends from build1878 commit `a87252b`, not current main. No upstream branches are overwritten.

The PR includes the previously uncommitted0801–0804 changes: separate course progress, assignment grade and Moodle competency confirmation; reviewer route and grade display; explicit BMS24 assessment wording; component-owned Priority/Competency translations. The final label patch alone changes five existing package files and adds one release note relative0803. It does not change schema or ACL.

## Recorded acceptance, not a new production approval

-0804 stage upgrade and repeat-upgrade, native PHP8.1 lint533/533 and Russian table/form browser check passed on2026-11-08.
- Isolated Linux scratch tests on2026-11-09: dev_ai_tools20/20 and dev_ai_cycle32/32 passed. Prior Windows symlink failures remain recorded as blocked, not passing.
- Historical0802 Moodle domain regression:15 tests /162 assertions; not a fresh full-suite0804 result.
- Installed candidate ZIP SHA256: `c1673b249e8c7f7b909cc16b1594f7d43c5f90286f45cc2689f4d40786d0ad8a` (1363 files).
- Local evidence: outputs/position-labels-2026110804/ACCEPTANCE.md and adjacent logs in the project workspace, outside this source repository.

## Release blockers / next work

1. Reconcile this functional line with PR48–50 schema fixes and retained main/Corporate components. Do not install0804 over a newer build or treat this branch as the integration baseline.
2. Repeat the complete Moodle/Corporate regression on the integrated tree. PR50 reports full acceptance HOLD despite its narrow schema fixes passing.
3. Expand browser acceptance for two-company boundaries, changed/repeated submissions and missing assessor rights. Narrow demo acceptance is not a complete tenant-isolation matrix.
4. Provide a scoped assessor-assignment UI and durable restart/deployment procedure in separately reviewed work.

CI builds candidates only. No merge, stage update or production deployment is part of this Git/PR task. CI success does not clear the integration and runtime blockers above.
