# ADR — v0.17.0 Role Competency Domain

## Decision
KBA business roles used for competency requirements are a separate domain from Moodle roles and from `local_kbacorp_member` access flags.

## Model
`business role -> competency -> target level` is owned by KBA Corporate Dashboard. Current competency results/evidence remain owned by Moodle core.

## Integration
External systems are mapped through provider-neutral records. Bitrix24 is the current sales/CRM system; future Odoo/ERP integration must reuse the same canonical BMS role and competency identifiers rather than introduce ERP-specific domain tables.

## Multi-role rule
When several active business roles require the same competency, the employee target profile uses the maximum target level. Priority resolves to the strongest value: critical > core > additional.

## Learning relationship
Courses develop competencies but do not define the business-role target profile. Role requirements may therefore appear before a learner has an enrolled course for that competency.
