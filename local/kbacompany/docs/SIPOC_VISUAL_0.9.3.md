# BMS24 0.9.3 — Visual SIPOC Editor

## Scope
Visual editing of the existing canonical process model. No second process engine and no new schema.

## Model
Supplier → Input → Process → Output → Customer.

- Suppliers/customers remain canonical process relations.
- Inputs/outputs remain existing `procelem` elements.
- Process steps are existing BPMN `task` elements.
- Primary output remains `process.productuuid` and is not duplicated as an editable SIPOC row.

## Editing
For a draft model the SIPOC tab opens a five-column card editor.

- Add/edit/remove Input, Process step and additional Output using existing element CRUD.
- Manage Supplier/Customer using existing process relations.
- Reorder Input / Task / Output cards using drag-and-drop or keyboard-accessible move buttons.
- Save order atomically against the expected model `changeversion`.
- Submitted UUID sets must exactly match the active elements of each reorderable type.

## Safety
- Company scope and existing management capability checks remain server-side.
- Published models remain immutable.
- Reorder uses the existing process-model lock and delegated transaction.
- Stale model revision is rejected.
- Publish remains a separate explicit action and existing SIPOC/BPMN validation still applies.

## Out of scope
- Reordering Supplier/Customer relations (current relation model has no order field).
- BPMN canvas / bpmn-js / BPMN XML.
- Process execution engine.
- Automatic RACI/STP generation changes.
