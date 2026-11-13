# BMS24 rc20 — Documents and Quality (build2026101341)

## Added
- Quality center consolidates canonical controlled documents, append-only policies/directives, and A3 corrective actions.
- Direct links to source records; legacy CRUD and URLs retained.
- Document visibility governed by document_service::master_list; policy visibility by policy_service::browse; A3 management restricted to managers.
- Server-side company UUID SQL scope; paginated policies, capped A3 results.
- RU/EN interface and navigation entry.

## Scope limitation
- A3 is an existing management-cycle review; it is **not** a formal nonconformity register.
- No standalone nonconformity model, investigation workflow or CAPA closure acceptance implemented in this build.
- Live Moodle acceptance: NOT RUN.
