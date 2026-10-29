# BMS Calendar Planning Views — 1.1.0-alpha7

## Decision

BMS24 Calendar remains a federated projection. Alpha7 adds Month / Week / Day presentation and controlled drag/drop only for manual Moodle events already editable under the existing calendar permission guard.

## Safety boundary

- Moodle manual event: may be moved only after POST + sesskey + server-side `require_manage_event`.
- Moodle generated/group/subscription event: read-only.
- BMS Task / Meeting / Battle Plan / Management Checkpoint: read-only projection; Calendar never changes the canonical BMS record.
- Company UUID remains the BMS boundary.
- No new event table, no hidden copy, no client-side authorisation.

## View model

Month uses the existing monthly projection. Week/day compose the same permission-scoped month projections for every month touched by the requested window, deduplicate canonical events, then reduce the result to the requested dates.

## Drag/drop semantics

Dragging changes only the calendar date. The event local clock time and duration are preserved. The user confirms the move before the POST is submitted.
