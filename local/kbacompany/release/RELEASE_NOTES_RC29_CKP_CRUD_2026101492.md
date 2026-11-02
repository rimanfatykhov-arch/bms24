# RC29 build2026101492 — ЦКП CRUD v0.3 pilot

- products.php remains legacy statistics page and company-scoped list. CKP rows now link to dedicated DetailView.
- Standalone product_record.php supports Create → DetailView → Edit, labels above fields, server-side validation, Cancel, preserved input on domain errors.
- Stable code immutable and server-generated; previously existing product UUID/code and linked metric rows unchanged.
- Server checks company scope, management permission, POST sesskey, active target organization record.
- Legacy CKP POST route maintained for integrations; after creation redirects to DetailView.
- No schema changes, no new product engine.
- Not yet implemented: full historical revisions/audit of product edits, automated Behat testing, all BMS form migrations. Before deploying to production test role and company isolation.
