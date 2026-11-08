# IDEF0 Foundation — local_kbacompany 0.9.6

## Scope
Read-only IDEF0 context diagram inside the existing Process card.

## Canonical mapping
- Function: existing BMS Process (UUID / PRC code / L0-L2 hierarchy).
- Input: existing `procelem` with `elementtype=input`.
- Control: existing `procelem` with `elementtype=control`.
- Output: primary Process Product plus existing `procelem` outputs.
- Mechanism: existing `procelem` with `elementtype=resource`.

No independent IDEF0 process, hierarchy or ICOM storage is introduced.

## Deliberately deferred
- ICOM CRUD;
- decomposition editor;
- automatic child-process creation;
- IDEF0 inter-process arrows;
- policy/position/system linking;
- IDEF0-to-SIPOC write synchronization.

## Versioning and security
The tab reads the same visible process/model revision as SIPOC/BPMN and preserves Company UUID scope and existing reader/manager visibility rules. No mutation endpoint is added.
