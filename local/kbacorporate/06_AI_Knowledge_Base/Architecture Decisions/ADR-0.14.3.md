# AI DECISION LOG — v0.14.3

## ADR-001: Bitrix24 is not called directly

Decision: Corporate Dashboard sends a declarative role schema to KBA Connect. KBA Connect remains the only component responsible for Bitrix24 REST and credentials.

Reason: avoids duplicated secrets, duplicated retry logic, and two independent Bitrix24 integrations.

## ADR-002: Role field is multiple enumeration

Decision: one Contact field stores multiple corporate-learning roles using stable XML_ID values.

Reason: one person can simultaneously be an employee and corporate manager; future department-manager and HR roles are supported without adding more Bitrix fields.

## ADR-003: No destructive provisioning

Decision: Dashboard exposes only create/check provisioning. There is no delete-field operation.

Reason: deleting a Bitrix24 user field can destroy business data and should never be triggered automatically from the learning dashboard.

## ADR-004: Dashboard and KBA Connect releases may be deployed independently

Decision: missing KBA Connect schema endpoints are represented as a diagnostic state, not a fatal error.

Reason: permits safe staged rollout while maintaining production availability.
