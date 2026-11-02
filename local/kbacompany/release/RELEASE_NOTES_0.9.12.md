# Release notes — local_kbacompany 0.9.12 build2026100607

## IDEF0 Controls & Mechanisms

Adds explicit canonical BMS-object links inside the existing IDEF0 Control and Mechanism areas without creating a second storage model. Controls can bind Policy/Directive, effective Document and Metric objects; Mechanisms can bind Organisation Units and Positions. The implementation reuses existing Semantic Core relation types and keeps descriptive `control` / `resource` Process Model elements intact.

Manager mutations use existing Process architecture guards (permission, sesskey, request receipt, required reason and optimistic Process revision). Reader visibility follows canonical endpoint visibility. No Process Model/SIPOC/BPMN, DB schema, ACL or privacy change is introduced.

Roles and information systems remain descriptive ICOM in this release because they do not yet have canonical Semantic Core endpoint types.
