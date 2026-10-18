# Architecture — BMS v0.5.15

v0.5.15 is a stabilization release over the existing 0.5 management contour.

It adds no database object and no parallel lifecycle. Batch transition is orchestration around the canonical Battle Plan service; Weekly Management Acceptance is an on-read composition of existing coverage, readiness and management-overview projections plus technical referential-integrity checks.

The KBA Theme remains presentation/App Shell only. All logic stays in `local_kbacompany`.
