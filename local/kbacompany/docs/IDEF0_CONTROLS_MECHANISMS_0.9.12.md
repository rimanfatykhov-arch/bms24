# IDEF0 Controls & Mechanisms — 0.9.12

## Scope

Release 0.9.12 enriches the existing IDEF0 Control and Mechanism areas with explicit links to canonical BMS objects. It does not create another IDEF0 object model.

### Canonical relations

Controls:
- `Process → governed_by → Policy`
- `Process → governed_by → Document`
- `Metric → measures → Process`

Mechanisms:
- `OrgUnit → executes → Process`
- `Position → executes → Process`

The first two use already supported `governed_by` semantics; metric and participant relations reuse existing Process/Semantic Core semantics.

## ICOM coexistence

Existing `procelem` types `control` and `resource` remain descriptive ICOM elements and are still edited only in a draft Process Model. Canonical Control/Mechanism links are Semantic Core relations and are displayed separately inside the same IDEF0 Control/Mechanism zones. No field is repurposed and no duplicate canonical object is stored.

## Visibility and safety

- All relation queries are company- and Process-scoped before fetch.
- Policies are visible to Readers only when published; Managers may link a current non-withdrawn Policy.
- Documents are limited to effective controlled documents through `document_service`.
- Metrics, organisation units and positions are resolved inside the same Company UUID.
- Create/archive requires management permission, sesskey, request receipt, mandatory reason and current Process revision.
- Archive can target only a relation matching one of the exact Control/Mechanism relation shapes for the current Process.

## Explicitly out of scope

- No new DB tables or fields.
- No automatic creation of ICOM elements.
- No SIPOC or BPMN mutation.
- No execution engine semantics.
- No automatic inheritance to child functions.
- Business Roles and information systems are not promoted to Semantic Core endpoints in this release; they remain descriptive ICOM until a canonical endpoint contract exists.
