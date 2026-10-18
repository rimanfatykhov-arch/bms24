# Process Architecture

## One canonical process model

SIPOC, BPMN and IDEF0 are representations over one canonical BMS process architecture.

Do not introduce separate competing process sources of truth.

## SIPOC

Uses canonical:
- Suppliers / Customers relations
- Input / Output process elements
- Tasks/steps
- primary process product

## BPMN

BPMN layout/XML must not silently replace canonical semantics.

Where current design treats BPMN as layout/round-trip:
- semantic drift must be rejected
- published model remains immutable
- import may update layout only if canonical semantics match

## IDEF0

IDEF0 represents canonical Process hierarchy and ICOM:
- Input
- Control
- Output
- Mechanism

Decomposition uses canonical process hierarchy.
Identifiers such as A0/A1/A11 are representation labels, not canonical identity.

## Published state

Published models are read-only unless a formal new draft/version workflow is used.

## Concurrency

Respect existing:
- changeversion/stale guards
- locks
- transactions
- draft/published lifecycle
