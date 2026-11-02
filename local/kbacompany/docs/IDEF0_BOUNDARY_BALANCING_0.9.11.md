# IDEF0 Boundary Balancing — 0.9.11

The release adds a diagnostic projection that checks whether a parent IDEF0 function and its direct decomposition are balanced against the same canonical Product identities.

## Rules

- Parent Input is covered only when the same canonical Product UUID is used by an Input of at least one direct child function.
- Existing sibling `Process → supplies → Process` relations remain the source of internal transfers; their 0.9.10 ICOM balance status is reused.
- A direct child with no outgoing sibling transfer is treated as a terminal function for boundary diagnostics. Its primary Product must be represented on the parent boundary by the parent primary Product or a canonical Output element.
- The parent primary Product must be produced by at least one terminal child function.
- Unbound parent Input/Output elements and functions without a primary Product are reported as formalisation issues. Names are never used as a fallback match.

## Visibility and safety

Manager diagnostics are draft-first for Process Model I/O, then published; Reader diagnostics use published Process Model data only. Process hierarchy visibility remains the existing Process visibility. All new reads are Company-scoped before fetch.

The release performs no automatic mutation. Remediation links open existing Process/ICOM CRUD screens. No new database tables, ACL rules, privacy contracts, SIPOC writes, BPMN writes, execution semantics or hidden draft creation are introduced.
