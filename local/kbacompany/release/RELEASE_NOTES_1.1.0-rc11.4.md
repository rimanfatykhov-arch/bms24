# local_kbacompany 1.1.0-rc11.4 — build 2026101231

## One-click staffing plan editing

The current approved staffing plan now exposes a prominent **Edit staffing plan** action. The action is a protected POST mutation (sesskey + manageorganization capability). It never edits the approved version in place. If a draft already exists for the same branch, that draft is opened. Otherwise BMS24 creates a new draft copied from the current approved plan and redirects directly into the full staffing editor.

No database schema, ACL or privacy changes.
