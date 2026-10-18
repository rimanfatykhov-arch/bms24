# AI Decision Log — v0.32.2

## Decision 1 — Reassessment is a distinct workflow

A completed training item is not equivalent to a confirmed competency level. A dedicated reassessment workspace is introduced instead of reusing the generic audit screen as the primary interface.

## Decision 2 — Immutable baseline snapshot

The baseline and target are copied from the completed development-plan item into the audit-item snapshot. Later changes to role profiles or current Moodle grades must not rewrite the historical point of comparison.

## Decision 3 — Cutoff-based evidence collection

Only evidence observed after the beginning of the development cycle may participate in the reassessment proposal. Earlier current grades and evidence represent the baseline, not the result of development.

## Decision 4 — Proposal is not confirmation

Automatically collected evidence may propose a level with status `pending`. It is never written as a confirmed competency level without an authorised reviewer action.

## Decision 5 — Moodle remains source of truth

Confirmed levels are written using `core_competency\api::grade_competency`. KBA stores workflow context, baseline, evidence and approval history, but does not establish a parallel authoritative grade.

## Decision 6 — No invented zero

Missing evidence or an unevaluated competency remains `missing`; it is not represented as level zero.

## Decision 7 — Non-destructive, idempotent upgrade

Installation does not create or rewrite reassessment records. One completed plan reuses its linked follow-up audit. Existing historic audits are preserved.

## Decision 8 — Rejected items can be resubmitted

A rejected proposal can be replaced by a new manual proposal. This reopens the audit, clears reviewer confirmation and preserves the broader evidence history.

## Decision 9 — Explicit input integrity checks

The workflow verifies that confirmed item IDs and manually submitted competency IDs belong to the current reassessment, preventing unrelated competencies from being injected through modified requests.

## Deferred

- automatic semantic scoring of uploaded files/text;
- reviewer comments on rejection as a dedicated structured field;
- immutable versioned methodology snapshot for every evaluation method;
- bulk selective confirmation instead of all pending proposals;
- automatic creation of the next plan without explicit user review.
