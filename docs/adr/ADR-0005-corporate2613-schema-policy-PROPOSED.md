# ADR-0005 — Corporate2613 fresh/historical defaults and indexes

Status: PROPOSED, owner decision required; NOT implemented.

## Verified context

Production1878/Corporate2613 metadata has member.isemployee default0 and
devplan.status defaultactive. Exact2613 install.xml declares1 anddraft respectively.
There are also two historical extra index structures on course(courseid) and
member(userid). Both MariaDB10.11 and Percona8.0 reproduce these differences.

Selective PR69 repair changes code-only; it preserves existing records, defaults,
indexes, membership rules and plan lifecycle. Strict fresh/upgrade comparison stays
FAIL/HOLD; we do not filter these discrepancies out of the gate.

## Decision to be made

Choose the intended defaults for NEW rows when callers omit these fields. Keeping
historical0 avoids implicitly treating omitted membership as employment. Historical
active, however, may imply a different plan lifecycle than freshdraft. Neither
direction is a neutral schema formatting change. Existing records must never be
mass-converted to match a fresh install.

The owner must approve explicit domain semantics before any default migration or
install.xml change. Index normalization is a separate physical-schema decision:
retain and declare the observed indexes, or prove redundancy and approve removal
with workload/locking/recovery evidence. Do not silently drop indexes for parity.

## Required evidence before implementation

- Trace every insert/default-dependent path and distinguish draft/active plan state.
- Tests for omitted and explicit0/1 membership, inactive/manual membership, and
  cross-company denial; no capability or role-assignment changes.
- Tests for explicit/omitted plan status and allowed approval transitions.
- Fresh and observed1878/2613 upgrades on both engines; unchanged existing values,
  constraints/indexes except the approved delta, and repeat/idempotence.
- Actual isolated backup restore, data preservation, browser/HTTP ACL and rollback.

Until a decision is accepted, only the bounded runtime/test repair is authorized
in this candidate; schema parity and production release remain HOLD.
