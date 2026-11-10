# BMS24 Fast Workflow

## Release cycle (Issue63)

Development → automatic checks → isolated integration and production-copy
acceptance → verified backup/restore → owner-approved publication → post-deploy
smoke and monitoring. Draft PR and CI alone do not complete a product increment.

For RC30 use one integration branch and Draft PR69, not another chain of fix PRs.
Pin one release SHA, ZIP SHA256, manifest and actual dependency versions; retain
Corporate2613 until compatibility is proved. Any missing/failed recovery or ACL
gate means HOLD. Production publication requires separate approval for that exact
candidate and target. Follow [the current runbook](deploy/RC30_RELEASE69_RUNBOOK.md).

The local feature steps below remain useful, but do not replace release acceptance.

1. Start feature:
   `.\scripts\start_feature.ps1 "task-name"`

2. Codex Architect:
   use `docs/tasks/ARCHITECT_TASK_TEMPLATE.md`

3. Codex Implementer:
   use `docs/tasks/IMPLEMENT_TASK_TEMPLATE.md`

4. Fresh Codex Reviewer session:
   use `docs/tasks/REVIEW_TASK_TEMPLATE.md`

5. Preflight:
   `.\scripts\preflight.ps1`

6. Review exact diff and commit explicit files.

7. Build release:
   `python scripts\build_release.py`

8. Install generated `INSTALL_*.zip` on test Moodle.

9. Run live gates appropriate to the change.

10. Deploy to production only after sign-off.
