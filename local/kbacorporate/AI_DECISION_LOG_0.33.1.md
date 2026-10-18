# AI DECISION LOG — v0.33.1

## ADR-0331-01 — Evidence is versioned, not overwritten

Decision: every submission is a separate immutable payload version.

Reason: implementation evidence is an auditable management record. Overwriting the same comment or file area destroys the history needed to understand what was reviewed and corrected.

## ADR-0331-02 — Reuse the existing task lifecycle

Decision: evidence review drives the existing task transitions instead of introducing a second task status machine.

Reason: one authoritative lifecycle avoids contradictions between the task and its evidence.

## ADR-0331-03 — Review is a different-user action

Decision: the submitting user cannot accept or reject their own evidence.

Reason: completion must be independently confirmed by the curator or an authorised company manager.

## ADR-0331-04 — Rejection creates a new future version

Decision: a rejected payload stays unchanged; the assignee submits the correction as the next version.

Reason: reviewer feedback must remain attached to the exact materials that were reviewed.

## ADR-0331-05 — Preserve legacy fields for integrations

Decision: the latest evidence summary and URL are copied to the existing task fields while the authoritative history lives in the new version table.

Reason: existing reports and integrations may still read those fields. Compatibility is preserved without allowing them to overwrite version history.

## ADR-0331-06 — No automatic migration of legacy evidence

Decision: pre-existing comments and files remain in the old filearea and are displayed read-only.

Reason: there is no reliable historical reviewer, decision time or version boundary from which to reconstruct a truthful audit trail.

## ADR-0331-07 — Two additive tables

Decision: use one table for evidence versions and one append-only table for evidence events.

Reason: payload state and audit events have different retention and query semantics. Separating them keeps each record clear and supports later analytics.

## ADR-0331-08 — Lock status after evidence workflow starts

Decision: after the first version exists, task status cannot be changed from the generic task editor.

Reason: direct edits could create contradictions such as a completed task with rejected or pending evidence.

## Rejected alternatives

- Reuse a single task comment and file area — rejected because updates destroy review history.
- Automatically approve after course completion or file upload — rejected because evidence requires an explicit management decision.
- Allow the owner to self-approve — rejected because it removes separation of duties.
- Convert all legacy files into v1 automatically — rejected because that would fabricate submission and decision history.
- Create a separate parallel evidence task — rejected because it would duplicate ownership, deadlines and statuses.
