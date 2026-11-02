# BMS 0.6.9.8 — SuiteCRM Administrative Scale UX

Build: `2026092138`

## Purpose
Make the Administrative Scale understandable as a record-management workspace while preserving the canonical BMS model.

## User-facing changes
- ListView shows records and opens a dedicated record card.
- DetailView shows status, traceability, semantic fields, related children and revision history.
- EditView groups fields into Basic information, Definition, Schedule and Advanced.
- Labels are kind-specific. For example, VFP uses “VFP name”, “VFP description”, “Completion / acceptance criteria” and “Linked BMS product” instead of generic labels.
- Stable Code remains server-generated and read-only.
- Technical sort order is hidden under Advanced.
- Publish, archive and Battle Plan actions are moved to the record card.

## Boundaries
No new business entities, KPI engine, task engine, ACL model, canonical identity or schema fields are introduced. The Theme remains presentation-only; all routes and UI model remain in `local_kbacompany`.

## Upgrade
Schema-neutral Moodle upgrade savepoint `2026092138`.
