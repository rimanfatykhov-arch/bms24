# RC29 build2026101711 — Latest linked task feedback coherence

Corrects a read-only regression in Weekly Management Coordination / recurring pattern cards. Previously, with a newer active linked management task and an older completed linked task, the card could show the older task's before/after facts next to the newer task. Now factual movement is shown only when the **latest visible linked canonical Task Center task** has status `done` and a canonical `completed_at` timestamp. In all other states (open / doing / review / returned / cancelled, or missing completion timestamp), the current task has no factual result. Existing bounded task trace lookup, Task Center visibility, employee scope, 4/8-week history, and factual result arithmetic stay unchanged. No DDL, score, causal inference, entity or write path.

## Regression gate

- latest active + older done: only latest task is shown; no stale factual result.
- latest cancelled + older done: no stale factual result.
- latest done with completed_at: compare bounded weekly facts, show after-data waiting state when appropriate.
- latest done without completed_at: do not synthesize completion date or movement.
- inaccessible history user: preserve build1710 warning and suppress CEO summary.
- full staging validation still required across two companies, employee/manager/CEO roles, Week Coordination, Task Center, Battle Plan and KBA/Boost responsive UI.

Static QA: local release runner. Live Moodle acceptance NOT RUN.
