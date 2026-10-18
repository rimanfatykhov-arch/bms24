# BMS 0.6.9.9 — Business Model CRUD Foundation

Build: `2026092139`

## Purpose
Move BMS Business Model Canvas 2.0 from one long textarea form to the canonical BMS CRUD pattern while preserving the existing `local_kbacompany_bizmodel` root and all downstream Strategy/Financial Model references.

## Canonical model
- `local_kbacompany_bizmodel` remains the canonical root Business Model record.
- Root revisions receive immutable human-readable `BMC-xxxx` Stable Codes; UUID remains canonical identity.
- `local_kbacompany_bmcitem` stores one Canvas statement per record (`BMCI-xxxx`).
- Every item is scoped to an exact `canvasuuid + canvasrevision` snapshot.
- AS-IS and TARGET are separate records, not two fields of one mutable note.
- AS-IS evidence markers: FACT / DATA / HYP / OPEN.
- TARGET markers: TARGET / OPEN.
- Sticky-note color is stored as change intent and never replaces evidence/state semantics.

## Snapshot semantics
A root BMC revision is the snapshot boundary.
- Editing a root revision creates a new draft root revision and clones the latest item state into it.
- Publishing creates an immutable published root snapshot and projects the same semantic item revisions as published.
- Creating a new revision from a published model creates a new editable draft snapshot.
- Item tombstones remain tombstones in descendants.
- Root archival keeps historical active item content readable while removing the model from active selection.

Snapshot cloning executes inside the same delegated database transaction as the root revision insert.

## Owner requirements
- The root BMC pins an exact published Owner Requirement package revision.
- Item-to-REQ links are stored in `local_kbacompany_bmcitemreq` and reference the exact pinned owner-frame revision.
- When a Canvas already contains records, silently changing/removing the pinned owner requirement package is blocked.
- Team Canvas sees only approved organizational REQ items; private OWNER-CONF material does not cross into the BMC record model.

## Methodology guidance
The EditView includes contextual guidance for all nine BMC blocks:
1. Customer segments
2. Value proposition
3. Customer relationships
4. Channels
5. Key activities
6. Key resources
7. Partners and suppliers
8. Revenue structure
9. Cost structure

For every block the UI shows:
- meaning/purpose;
- questions to answer;
- typical mistake;
- AS-IS discipline;
- TARGET discipline;
- FACT/DATA/HYP/OPEN guidance;
- evidence/source/test-criterion fields.

Key UI rule: TARGET describes a state that must become true, not an action such as “implement CRM”, “hire a director” or “launch advertising”.

## BMS CRUD
The new workspace follows `BMS-UX-CRUD-001`:
- ListView of Business Models;
- DetailView / Record header;
- Canvas aggregate view;
- Block view with separate AS-IS and TARGET columns;
- Item DetailView;
- Item EditView;
- REQ coverage view;
- comments and item history;
- root revision history;
- technical sort order under Advanced.

Legacy textarea Canvas remains available as **read-only compatibility** and is not a second write path.

## Boundaries
This release does **not** create GAP, Change, Project, Program, KPI or Financial Model duplicates.
- GAP Registry and five-contour TARGET validation are deferred to the next Business Model increment.
- Financial Model remains a separate canonical domain.
- Existing Strategy/Financial Model references to `local_kbacompany_bizmodel` remain valid.
- BMS Indicator remains the future KPI/result source of truth.
- Administrative Scale and Battle Plan remain the future change/execution sources of truth.

## Schema
Additive changes:
- `local_kbacompany_bizmodel.stablecode`
- `local_kbacompany_bmcitem`
- `local_kbacompany_bmcitemreq`
- `local_kbacompany_bmccomment`

Privacy provider covers BMC item actors and discussion comments.
