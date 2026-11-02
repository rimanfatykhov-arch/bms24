# RC29/build2026101477 — bounded RBAC tasks & battle plans

- Company-level `task:edit` grants the same server-side manager operations as existing `can_manage_tasks` for canonical tasks, including company-wide visibility and management. Only grant to trusted roles.
- Company-level `battle_plan:edit` separately grants existing battle-plan manager operations, including reviews/approvals; it does not grant `task:edit`.
- Both granted by owner/site admin under Settings → Roles and Rights. Separate audit and assignment revocation via existing ACL registry.
- These are **broad manager grants**, not fine-grained edit-only. UI marks view/create as not implemented (`—`) for the two objects; detailed object scopes and independent actions remain NOT IMPLEMENTED.
- Current legacy task managers retain prior access. No automatic role assignment; no new tables. No live validation. Do not treat this as complete RBAC 2.0.
- Verify: company A assigned role with task edit can manage its tasks but not B plans; B plan grant permits plans but not canonical tasks; company B remains inaccessible; revocation immediately restores old legacy permission only.
