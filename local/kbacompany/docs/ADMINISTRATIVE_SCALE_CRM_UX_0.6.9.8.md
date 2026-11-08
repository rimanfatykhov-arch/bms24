# Administrative Scale CRM UX — 0.6.9.8

## UX contract
The Administrative Scale follows the same record workflow used by CRM systems:

`ListView → DetailView → EditView`

The data model remains `local_kbacompany_adminitem`; presentation differs by `kind`. `administrative_scale_ui_service` defines sections, field visibility, required markers and kind-specific labels, while `administrative_scale_service` remains the only business-validation/persistence boundary.

## EditView
Semantic sections:
- Basic information: Stable Code, name, parent/source, accountable Position.
- Definition: fields that describe the selected kind.
- Schedule: only for execution-oriented kinds.
- Advanced: technical sort order, collapsed by default.

Examples:
- Plan: Situation → Goal of this Plan → Plan.
- Statistic: purpose → BMS Indicator → target/normative value.
- VFP: VFP description → completion/acceptance criteria → optional linked BMS Product.

## DetailView
Shows record status, full Administrative Scale trace, semantically named values, related child records and append-only revision history. Management actions are located on the record card, not repeated as dense list-row controls.

## Identity and ownership
- UUID remains canonical identity.
- Stable Code remains server-generated and immutable.
- Company UUID remains the isolation boundary.
- No Theme business navigation or logic is added.
