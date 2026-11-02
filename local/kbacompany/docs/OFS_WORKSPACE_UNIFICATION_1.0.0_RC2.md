# OFS Workspace Unification — 1.0.0-rc2

Acceptance defect addressed: `ofs.php` and `ofs_designer.php` previously exposed canonical live OFS and versioned OrgDesign as visually separate products with different product semantics.

## Result
- `ofs.php` is the unified OFS Workspace.
- Modes: Current, Design, Compare, Check.
- One shared poster renderer is used for live and project states.
- OrgDesign payload supports optional `companyproductuuid` and per-node `productuuid` without a database migration.
- Legacy `companyproduct` / `node.product` text is preserved and shown as a fallback requiring formalisation.
- New design snapshots created from live state carry existing canonical Product UUIDs.
- Comparison is read-only and reports added/changed/removed nodes plus product-formalisation warnings.
- Pre-apply Check combines existing structural readiness with canonical-product readiness.
- New nodes cannot reference a canonical node Product before a live OrgNode exists; their project text is retained and flagged.
- No automatic Product creation, no hidden Apply, and no second OFS source of truth.

## Compatibility
`ofs_designer.php` remains callable for legacy links/forms. GET redirects to the unified Workspace; POST continues to use the existing versioned design commands and redirects back to Design mode.
