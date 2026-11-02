# BMS24 1.1.0-rc29 — build 2026101663

## Battle Plan correction feedback

- Returned Battle Plans now surface the latest reviewer correction request while the plan is back in `draft`.
- The banner shows reviewer, decision time, comment and the concrete tasks marked for correction.
- Authorized editors get direct actions to edit the plan and open its tasks; review history remains one click away.
- The same feedback is visible on the plan detail shell and on the edit screen.
- Existing `submitted -> changes -> draft -> submit` lifecycle is unchanged.
- No DB schema, ACL/RBAC, privacy, or company-isolation changes.
