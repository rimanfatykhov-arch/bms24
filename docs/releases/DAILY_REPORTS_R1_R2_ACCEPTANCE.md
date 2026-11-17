# R1/R2 independent acceptance — not production permission

Source: #118 e5ee31b + #119 fa7c4eb, now merged by another operator into main2739a5d. This release branch adds
independent acceptance only; no parallel implementation or changes to Claude's feature branches.

## Baseline and identity

Published production R0 is source9736452, plugin2026111100, accepted validatorf7b4c09. Their trees are identical314bdd6.
All71 baseline BROWSER checks remain mandatory. Candidate2026111601 requires all79 checks; no skips count as PASS.
The PR integration base2739a5d differs from the deployed baseline9736452 and is pinned separately. The package manifest
must match the exact PR base/head/tree; testing the older deployed product does not permit a mismatched candidate.

## Additional migration acceptance

Both release jobs install the same immutable artifact on MariaDB10.11.19 / Percona8.0.46-37, Moodle4.1.22/PHP8.1.
The accepted R0 baseline is installed and populated by its own71-check browser fixture before the snapshot. Then:

1. Record actual plugin1100, every existing BMS/Corporate domain table and files schema, counts and content hashes.
2. Upgrade the exact package to1601. Exactly five new tables, no old table schema/data mutation or backfill.
3. Repeat upgrade and require identical domain schema/data. Compare all domain schemas with a separate fresh install.
4. Run existing task/message/file/menu/ACL checks plus the new8-check daily report workflow.
5. Repeat upgrade with submitted/reviewed reports, then feature off/on; rows and immutable versions must survive.
6. Full restore of the synthetic pre-upgrade snapshot, ACL/file verification, then reinstall and repeat smoke.

Synthetic snapshot restore is a rehearsal only. It is **not** permission to overwrite real/new reports. Production
mitigation after1601 is feature off + forward fix; code downgrade below1601 is incompatible with Moodle.

Negative policy tests reject wrong baseline/target versions, missing/extra tables, old data/schema changes, backfill,
fresh/upgrade mismatch, repeated-upgrade data loss and a fake or destructive feature switch, under normal Python/-O.

## Separate real-data requirement

Actions uses synthetic data. Evidence from an existing private isolated restoration of production data must be recorded
separately; do not label this CI report as a real-data migration. No fresh production backup, production/staging upgrade,
old-copy deletion, merge or deployment is authorized by this branch. Existing recovery storage and isolation must be
checked before using any clone. Private data/credentials/paths stay outside Git.

## Pending

Results, exact release SHA/ZIP/manifest, independent P1 reproduction review and real-data engine matrix are pending.
