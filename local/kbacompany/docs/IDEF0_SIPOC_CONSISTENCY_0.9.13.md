# IDEF0 ↔ SIPOC Consistency — 0.9.13

## Purpose

0.9.13 adds a read-only integrity projection between IDEF0 and SIPOC for the same canonical Process Model.

IDEF0 and SIPOC do not own separate Input/Output stores. Both views reuse the same `local_kbacompany_procelem` input/output rows and the same canonical Process primary Product. The release therefore does not synchronise one model into another. It verifies that both projections remain aligned and reports missing Product formalisation separately from projection drift.

## Checks

- IDEF0 Input projection and SIPOC Input projection contain the same canonical element UUIDs and Product UUID bindings.
- IDEF0 secondary Output projection and SIPOC secondary Output projection contain the same canonical element UUIDs and Product UUID bindings.
- The primary Process Product is the same primary Output exposed to both views.
- Every Input is explicitly bound to a canonical Product where formalisation is required.
- Every secondary Output is explicitly bound to a canonical Product where formalisation is required.
- Missing primary Process Product is reported as a formalisation issue.

## Statuses

- `consistent`: the projections match and all checked I/O is canonically formalised.
- `needs_formalisation`: projections match, but one or more I/O elements lack a canonical Product binding or the primary Product is missing.
- `inconsistent`: the two projections differ by canonical element/Product identity. This indicates projection integrity drift and is not auto-repaired.

## UI

The IDEF0 workspace shows a dedicated `IDEF0 ↔ SIPOC` section with score, I/O counts, primary Product state and canonical Product coverage. The SIPOC read view shows the same consistency state with a link back to IDEF0.

Remediation uses the existing Process/ICOM/SIPOC CRUD only. No hidden draft creation or automatic writes are introduced.

## Explicit non-goals

- no IDEF0-to-SIPOC copy;
- no SIPOC-to-IDEF0 copy;
- no new database table;
- no schema, ACL or privacy changes;
- no automatic Supplier/Customer inference from IDEF0;
- no BPMN/procflow mutation;
- no execution semantics.

Supplier and Customer remain SIPOC context fields and have no direct IDEF0 ICOM equivalent in this release.
