# AI Decision Log — v5.0.35.1

Decision:
External Bitrix identifiers and local Corporate Dashboard primary keys are separate namespaces.

KBA Connect must never guess that a Bitrix Company ID is a local Corporate `companyid`.
The Corporate bridge owns that mapping. Role merge executes only when the bridge returns
an explicit local company identifier.
