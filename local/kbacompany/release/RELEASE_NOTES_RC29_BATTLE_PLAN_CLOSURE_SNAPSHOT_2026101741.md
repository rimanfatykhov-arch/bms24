# RC29 build2026101741 — Battle Plan close-time snapshot

- Successful active-to-closed transition atomically captures versioned `closure_snapshot` inside existing plan `statepayload`.
- Snapshot records item UUID, provider, canonical task ID, status at closure, and rollover status.
- Manager prior-week summary uses frozen statuses for newly closed plans, with canonical task ACL checks. For older plans it labels live-status counts explicitly.
- Snapshot is at **actual closure time**, not necessarily week end. No backdating or invented historical status.
- No DDL, no task duplication, no implicit rollover, unchanged permissions.
