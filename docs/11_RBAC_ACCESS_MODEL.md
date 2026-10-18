# RBAC / Access Model Notes for Codex

The project has a dedicated current RBAC 2.0 specification on Google Drive.

Engineering rules:

- Do not infer access merely from UI visibility.
- Server-side capability checks are mandatory.
- Company scope and capability checks are separate controls.
- Domain actions may require stronger permission than record view.
- Reader/Manager distinctions already exist in multiple architecture modules.
- Published/draft visibility may depend on role/capability.
- New capabilities require explicit DB/access review and upgrade compatibility.

Before changing `db/access.php` or permission semantics, consult the current RBAC specification and the current code.
