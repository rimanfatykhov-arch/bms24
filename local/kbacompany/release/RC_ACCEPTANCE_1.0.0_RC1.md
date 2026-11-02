# 1.0.0-rc1 — Live Acceptance Contract

This document defines the exit gate for the first BMS24 `local_kbacompany` 1.0 Release Candidate.

## Invariants

- Exact functional baseline: `0.9.17 build2026100612`.
- No new schema, capability, privacy storage contract, scheduled task or provider contract in rc1.
- Company UUID remains the security boundary; data must be scoped before fetch.
- KBA Theme remains presentation/App Shell only; BMS routes, permissions and data remain in `local_kbacompany`.
- Manager visibility is draft-first where the existing domain contract provides it; Reader visibility is published-only.
- Published Process/Process Model revisions remain immutable.
- Diagnostic scores, A-notation, architecture health and analytics remain computed projections, not canonical stored facts.
- Exported Process Passport HTML is a snapshot, not a second source of truth.

## Mandatory live gates

1. **Install / upgrade**
   - install/upgrade completes on the test copy of Moodle 4.1.22;
   - no unexpected upgrade step or schema drift;
   - plugin reports `1.0.0-rc1 / build2026100613`.

2. **Company isolation**
   - two-company smoke test for Process Registry, Architecture, Analytics, Process detail and exports;
   - no cross-company search/autocomplete/export leakage.

3. **Process Architecture**
   - Registry, Architecture and Analytics open and preserve filters;
   - L0→L1→L2 hierarchy and drill-down are correct;
   - readiness/coverage analytics match the visible filtered slice.

4. **IDEF0**
   - Passport→IDEF0 tab route works with `idef0` intact;
   - I/C/O/M CRUD obeys draft/published rules;
   - decomposition and sibling interconnections render correctly;
   - ICOM handoff balance and parent boundary balance behave as specified;
   - Controls/Mechanisms canonical bindings render and mutate only through existing Semantic Core rules.

5. **SIPOC / BPMN**
   - Visual SIPOC loads under real KBA Theme/CSP;
   - IDEF0↔SIPOC consistency status agrees on both views;
   - BPMN canvas loads; layout save/download, stale/replay guards, lane membership and structural invalidation work;
   - published model remains immutable.

6. **Process Passport / export**
   - Passport opens for Manager and Reader with correct visibility;
   - print view is readable;
   - downloaded HTML snapshot opens outside Moodle and contains no privileged hidden data;
   - source fingerprint is present and stable for the same visible revision.

7. **Cross-contour regression**
   - People, Positions, Documents, Policies/Directives, Tasks/Communication Center, Deviations, Battle Plan and Management Cycle critical flows still open and save;
   - existing Bitrix/Odoo integration boundaries and secrets remain unchanged.

8. **UX / browser**
   - desktop and mobile visual acceptance in the real KBA Theme;
   - no horizontal overflow except intentional diagram canvases;
   - keyboard/focus/anchor navigation is usable;
   - browser console has no new uncaught errors on the acceptance flows.

## Exit criteria

- all mandatory live gates PASS;
- no unresolved P0/P1 defect;
- any P2 accepted explicitly with owner and follow-up release;
- live evidence is attached to the release record;
- only then may the next release be promoted from RC to production candidate/final.
