# BMS24 UI Filter / Selection Standard

Canonical UI Kit contract for BMS24 filter and context-selection interfaces.

## Filter panels
- Use `bms24_ui_kit_service::filterbar()` rather than page-specific filter markup.
- Desktop: two-column field grid.
- Mobile (<=720 px): one column.
- Labels sit directly above controls; controls use shared UI Kit sizing.
- Actions are a separate row below fields.
- GET filters use `Apply / Reset`; reset returns to the canonical route/context.
- Hidden scope/company values must be passed through typed hidden data, never raw HTML.

## Selection / create forms
- Use the same two-column `fieldgrid` visual pattern.
- Workflow actions retain their semantic labels, e.g. `Continue / Cancel` or `Save / Cancel`; do not use `Reset` when the screen is not a filter.
- Business validation and ACL stay in the owning service/page; UI Kit is presentation-only.

## Architecture boundary
UI Kit owns layout and rendering only. It must not query company data, infer permissions, persist filter state, or alter business rules.
