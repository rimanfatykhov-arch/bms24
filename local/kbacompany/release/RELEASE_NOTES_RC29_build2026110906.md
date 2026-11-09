# BMS2026110906 — task membership, batch and deadline acceptance

Base PR59 head2446347; Corporate2026110901 unchanged. Three fixture failures
reproduced on unchanged baseline before edits. Assign reviewers and foreign
assignees who actually belong to their company. Preserve original batch/deadline
assertions and strengthen UUID, revision, audit and foreign-task checks.

New domain-service regressions cover native inactive/removed membership, removed
Corporate membership, observer read/write separation, nonmember participants,
real generated-task source preservation, and capability/company-grant revocation.
No authorization, Corporate API, Moodle Core, schema or business-rule changes.
0906 is a code-only checkpoint identifying the new test-bearing artifact.
Full isolated MariaDB/Percona acceptance is required, including exact0905 upgrade.
RC30 remains HOLD until all gates pass. No merge or staging/production deployment.
