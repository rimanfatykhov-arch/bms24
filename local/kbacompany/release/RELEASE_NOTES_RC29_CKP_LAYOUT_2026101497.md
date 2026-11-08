# RC29 build2026101497 — CKP Create/Edit layout hotfix

- Fixed root cause: global `.kbacompany-form` CSS uses a two-column grid.
- CKP Create/Edit explicitly uses a scoped `.kba-ckp-crud-form` one-column flex layout.
- Field pairs are stacked vertically with full-width inputs and explanatory text in context.
- Organization owner radio controls are left-aligned with labels, company/node/position pickers remain company-scoped and server-validated.
- Cancel / Save actions are aligned at the bottom right.
- Existing product codes, UUIDs and relations remain unchanged; no DB schema changes.
- Needs live Moodle browser acceptance.
