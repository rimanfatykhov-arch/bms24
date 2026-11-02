# local_kbacompany 1.0.0-rc1 — Stabilization & Acceptance Baseline

Release Candidate baseline over the exact 0.9.17 Process Architecture Analytics release.

This release freezes the current functional scope for live acceptance. It does not introduce a new business feature, data model, route, capability, background task or integration contract.

## Candidate purpose

- provide one immutable package for Moodle 4.1.22 live acceptance;
- verify the completed Process Architecture line end to end without changing its canonical semantics;
- make the remaining production gates explicit and auditable;
- keep the 0.9.17 data/schema/API behaviour byte-identical except for release metadata and RC documentation.

## Acceptance focus

- install/upgrade on the test copy of Moodle 4.1.22;
- KBA Theme desktop/mobile rendering;
- Company UUID isolation;
- Manager draft-first / Reader published-only visibility;
- published immutability and draft/revision workflow;
- Process Registry / Architecture / Analytics;
- Passport / IDEF0 / SIPOC / BPMN tabs;
- IDEF0 decomposition, interconnections, ICOM balancing, boundary balancing and Controls/Mechanisms;
- IDEF0↔SIPOC consistency;
- Process Passport, print view and downloadable HTML snapshot;
- browser filter/drill-down, responsive behaviour, print/export and CSP-sensitive BPMN/SIPOC visual flows;
- regression of existing Management, People, Documents, Policies, Tasks and integration contours.

## Production status

RC means candidate for acceptance, not production approval. Production readiness requires the live gates in `RC_ACCEPTANCE_1.0.0_RC1.md` to be completed and recorded.
