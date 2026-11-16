# R0 daily reports entry: independent release acceptance

Source: Claude PR116 head5d2abda11f29fb5f9a6fb03179ba696308d3f5d9.
Base: main881aac2ef334c4894b9cff2f591b75f675360e3b, identical product to published114.
Source worktree was clean and PR116 targets main. Claude's branch is not edited by the operator.

R0 is only the entry to the existing personal read-only summary: menu after Tasks, active route,
My report tab, Task Center button, short phone labels with full accessible names. It does not
implement saving/submitting/reviewing reports. R1/R2 remain separate PR118/119 under ADR0009/PR115.

The existing feature-branch workflow skips release packaging/rehearsal. This operator-owned release
branch supplies an exact CI package and both existing MariaDB/Percona rehearsals without changing
workflow applicability or claiming a skip as PASS. Source116 FULL/BROWSER/gate results are useful
handoff evidence, not automatic acceptance of this distinct release head.

Mandatory acceptance:

- Exact release head/tree, original CI ZIP and manifest SHA256; every packaged file matches Git.
- No DB/version/Core/Corporate changes or dropped published114 files. Preserve communication center,
  task-open history110, day-summary113, message edit/delete103 and attachments/menu102.
- Current FAST, STANDARD (new navigation and summary tests actually executed), FULL, BROWSER on both
  engines, Merge gate and both package install/restore/reinstall rehearsals. No weaker validators.
- Real owned/legacy Moodle desktop/mobile navigation and accessible names, Task Center button,
  report date selection and source task/history/card actions; no unexpected HTTP/JS/network errors.
- The same private isolated synthetic dev, separate credentials/volumes, outbound deny, cron/queues/
  real integrations off. CLI cache purge runs as the web UID. Working stage41/test is untouched.
- Only Codex installs the shared dev. R0 package is frozen during owner review; R1/R2 cannot replace it.

Production is NOT authorized by this task. No prior114 deployment window/approval applies.
Owner review then a separate exact-package publish command are required. R0's compatible fallback
is previous114 code plus cache purge, preserving all new user records/files. This code-only policy
does not apply automatically to R1/R2 schemas, versions, submissions or reviews.

Roadmap changes live in PR117 into the open canonical-plan PR95; neither is merged by this task.
Accepted decisions, implementation, tested evidence and actual publication must remain distinct.
Current acceptance statuses, precise hashes, runtime evidence and owner review are recorded by the
operator in the release PR and private/local evidence rather than guessed here.

The first release120 rehearsal stopped before installation: origins still pinned old main d353509.
The operator updated the exact baseline to published114 main881aac2 / validator153e4bd, whose full
Git trees are equal f3bda188. The runner verifies both trees and the full accepted64-check baseline;
candidate/reinstall retain71checks including7new R0 checks. The removed Recent activity block must
be absent in baseline/rollback/candidate/reinstall while History remains nonempty and accessible.
Old pins/provenance are retained in origins.previous_release. Six policy tests normal/-O pass,
including bad product/validator/tree and missing/extra/empty baseline checkset refusals;37 browser
contract tests pass. No product code or CI applicability was changed by this operator correction.
