# ADR-0005 — Corporate2613 fresh/historical defaults and indexes

Status: ACCEPTED by owner, 2026-11-10. Filename retained for existing links.

## Accepted decision (supersedes proposal below)

New omitted-field inserts use member.isemployee DEFAULT0 and devplan.status
DEFAULT'draft'. Existing rows, IDs, values, links and ownership remain unchanged.
Corporate2026111004 (0.40.13-bms.2) follows code-only1003; BMS stays2026111002.
Both known column definitions/defaults are validated before ALTER, defaults are
changed through XMLDB, postconditions precede savepoint. Unknown definitions stop.
No DML, Core, role/capability or runtime authorization changes are authorized.

Retain both historical index pairs named below. Only these exact extra copies
may differ from fresh, with names, columns/order, uniqueness, prefixes and type
checked. The comparator removes them only from a comparison copy, never the DB;
all remaining schema is compared. No table/test exclusions or general waiver.
Two results are distinguished: historical Corporate preservation compares full
physical metadata including index names and ordinal positions before/after;
fresh compatibility compares all logical definitions and native column/index
structures (types/null/default/extra/charset/collation, ordered columns, prefix,
type and multiplicity). XMLDB-generated names and column ordinal positions may
differ between fresh and a historical upgrade. They are fully listed in the
report and preserved historically, not normalized by DDL. This is structural
compatibility, not a claim of complete physical identity. No structural exception
beyond the two named duplicate indexes is allowed.
Future index cleanup requires a separate decision and workload/recovery evidence.

Known runtime add_member calls explicitly write employee1/manager0; both plan
creation paths explicitly write draft. ERP/bridge/license/member callers use
the service. No index-name optimizer hints found in checked source. Unknown
external SQL/workload remain unverified. Plan owner/pending-state tests are not
HTTP capability/session acceptance; revoked-membership self-plan access requires
clone browser review without silently changing the existing authorization policy.

Both engines must pass fresh, observed1878/2613 upgrade, repeat, Corporate all-table
row hashes/counts, physical schema policy and full PHPUnit. Historical raw schema
FAIL is retained as an observation; only exact known output plus pinned source
and complete metadata model establish the baseline. Candidate schema must PASS.
Synthetic reconstruction is not backup restore. Production release remains HOLD
pending actual isolated recovery/browser/rollback and separate owner approval.

## Historical proposal (superseded by accepted decision above)

## Verified context

Production1878/Corporate2613 metadata has member.isemployee default0 and
devplan.status defaultactive. Exact2613 install.xml declares1 anddraft respectively.
There are also two historical extra physical indexes on course(courseid) and
member(userid). Each duplicates an existing nonunique index with the same ordered
columns: `mdl_locakbaccour_cou_ix`/`mdl_locakbaccour_cou2_ix` and
`mdl_locakbacmemb_use_ix`/`mdl_locakbacmemb_use2_ix`. Compare multiplicity/names,
not a set that would hide duplicates. Both engines reproduce these differences.

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

Proposed owner choice (NOT accepted): member.isemployee DEFAULT0 and devplan.status
DEFAULT'draft'; never rewrite existing rows. Exact2613 repository::add_member()
explicitly writes1 for employee and0 for manager. Its bridge/ERP callers use that
service. Both development_plans insert paths explicitly write STATUS_DRAFT.
Thus known service-created values stay unchanged, but omitted-field external/sibling
callers may behave differently and require inventory. Retain duplicate physical
indexes pending a separate workload/locking/recovery decision; do not conflate
their cleanup with the membership/lifecycle decision.

- Trace every insert/default-dependent path and distinguish draft/active plan state.
- Tests for omitted and explicit0/1 membership, inactive/manual membership, and
  cross-company denial; no capability or role-assignment changes.
- Tests for explicit/omitted plan status and allowed approval transitions.
- Fresh and observed1878/2613 upgrades on both engines; unchanged existing values,
  constraints/indexes except the approved delta, and repeat/idempotence.
- Actual isolated backup restore, data preservation, browser/HTTP ACL and rollback.

Until a decision is accepted, only the bounded runtime/test repair is authorized
in this candidate; schema parity and production release remain HOLD.
