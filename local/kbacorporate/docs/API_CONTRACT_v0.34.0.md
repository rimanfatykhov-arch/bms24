# API Contract v0.34.0

No new external web-service function is published.

Internal read model fields include:

- `beforeaudit`, `afteraudit`;
- `beforeindex`, `afterindex`, `indexdelta`;
- `criteria[]` with stable criterion code, scores, normalised delta, gaps, status and risk transition;
- `sections[]` with normalised averages and status;
- `tasks[]` and accepted evidence counts;
- `economic.rows[]` with values, delta, percent delta and direction-aware outcome.

A public ERP/analytics API may consume this projection in a later version, but is not part of `v0.34.0`.
