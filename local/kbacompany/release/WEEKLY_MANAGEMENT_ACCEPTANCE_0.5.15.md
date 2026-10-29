# v0.5.15 — Weekly Management Acceptance

Acceptance is a read-model, not a stored approval record.

## Hard gates

1. Every factual employee+position assignment expected for the selected week has a canonical Battle Plan.
2. No Battle Plan is blocked by the existing readiness contract.
3. No draft or submitted plan remains when the week is accepted as operationally active/completed.
4. No duplicate `employee + position + week` Battle Plan key exists.
5. No orphan Battle Plan item references a missing plan.
6. No BMS-provider Battle Plan item references a missing canonical Task. A soft-deleted Task remains a resolvable historical tombstone and is not treated as a broken reference.

## Attention, not hard lifecycle rules

- active positions without critical indicators;
- critical indicators without an effective norm;
- indicator exceptions still requiring a management decision;
- overdue canonical Tasks.

These signals keep the week in `accepted_with_attention` but do not invent new Battle Plan transition rules.

## States

- `blocked` — at least one hard gate failed;
- `accepted_with_attention` — hard gates passed, but management/configuration attention remains;
- `accepted` — hard gates and attention checks are clear.

No percentage or time threshold is hidden inside this classification.
