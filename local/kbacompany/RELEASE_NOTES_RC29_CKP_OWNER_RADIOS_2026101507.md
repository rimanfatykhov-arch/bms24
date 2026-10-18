# RC29 build2026101507 — CKP form organizational ownership controls

- Fixed the visually separated radio controls/labels shown in FireShot Capture 117.
- Removed shared Bootstrap `form-check-*` classes from CKP scope choices. Added component-scoped `kba-ckp-owner-*` classes with compact native radio dimensions, a shared flex row and left-aligned clickable label.
- Same scoped CSS in `styles.css` and inline fallback for Moodle caches.
- Maintained Create/Edit vertical layout, company/node/position target switching and server-side company-scoped validation.
- Audit for metrics RBAC remains open: requires live integration tests with multiple companies and actor roles; do not mark as security certified.
- No database schema changes.
