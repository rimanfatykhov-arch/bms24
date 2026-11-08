# 0.9.16 — Process Documentation / Export

Generated read-only process passport over the existing canonical BMS24 process architecture.

## Scope
- New `process_document_service`: one projection from Process + visible Process Model + IDEF0 decomposition + SIPOC + BPMN/RACI + Semantic Core Controls/Mechanisms.
- New `process_document.php`: BMS screen, print-friendly standalone HTML and downloadable HTML snapshot.
- Process detail and Process Architecture nodes link to the generated passport.
- The passport includes identity, owner/product, purpose, model scope/trigger/start/end conditions, ICOM, decomposition, interconnections, boundary/consistency diagnostics, SIPOC, task procedure/RACI, metrics/documents/systems/resources/records/controls and a source fingerprint.

## Invariants
- No new tables or persistence.
- Export is a snapshot, never a source of truth.
- Manager visibility remains draft-first; Reader remains published-only through existing services.
- All reads remain Company-scoped before projection.
- No hidden draft creation and no Process/Process Model/SIPOC/BPMN/Semantic Core writes.
