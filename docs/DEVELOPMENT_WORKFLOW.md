# BMS24 Fast Workflow

RC30 uses one integration candidate, Draft PR69, through development → isolated
testing → owner-approved publication. Follow [the current release runbook](deploy/RC30_RELEASE69_RUNBOOK.md).
Every failed/missing recovery or ACL gate keeps HOLD; CI is not publication approval.

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
