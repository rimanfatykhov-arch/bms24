# Visual BPMN — 0.9.4

Build: `2026092901`

## Purpose

Add a visual BPMN canvas to the existing versioned BMS Process Model without introducing a second process/task engine.

## Model ownership

Canonical semantics remain in the existing records:

- `local_kbacompany_procmodel` — versioned process-model revision;
- `local_kbacompany_procelem` — lanes and BPMN nodes;
- `local_kbacompany_procflow` — sequence flows;
- `local_kbacompany_procraci` — RACI;
- Process/Product/Org/Position objects remain canonical elsewhere.

BPMN XML is a **version-scoped visual layout artifact** stored in Moodle file storage under the existing process-model `modelid`. It does not become a parallel source of process semantics.

## bpmn-js

The browser loads the pinned `bpmn-js 18.30.1` modeler distribution from UNPKG. The server stores no third-party JavaScript bundle in this release. If the CDN is unavailable, the structural editor remains usable and canonical data is unaffected.

## Allowed visual changes

The canvas may persist diagram interchange/layout changes such as shape positions and sequence-flow waypoints.

The server rejects a BPMN XML save if it changes:

- node UUID identity;
- node BPMN type;
- node name;
- sequence-flow identity/source/target/label;
- lane identity/name/membership;
- unsupported BPMN semantic elements.

Semantic edits continue through the existing structured process-model actions. Published revisions stay immutable.

## Versioning

- If no layout file exists, BPMN XML is generated deterministically from canonical structural records.
- Saving visual layout stores `diagram.bpmn` for the current draft model revision.
- A new draft clones the last published revision layout if present.
- Structural node/flow edits invalidate the stored layout and regenerate it from canonical records.
- Publishing guarantees a version-scoped BPMN XML snapshot exists.

## Security / consistency

- Company UUID remains the security boundary.
- Existing management ACL is required for edits.
- Draft `changeversion`, process-model lock and DB transaction are required before layout save.
- XML is size-limited, DOCTYPE/ENTITY are rejected, and semantic equivalence is checked server-side.
- No BPMN execution engine, cron worker, automatic task generation, AI engine or new business table is added.

## Not in 0.9.4

- semantic create/delete/reconnect directly from canvas;
- BPMN XML upload/import from arbitrary files;
- executable BPMN/token engine;
- properties panel replacing BMS executor/product forms;
- automatic RACI/STP mutation from canvas.
