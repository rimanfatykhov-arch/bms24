# local_kbacompany 0.8.9 — Next Period & Rollover

Build: 2026092408

- Explicitly prepares the next Management Cycle period from an active/closed cycle.
- Preserves owner, curator, scope, priority, outcome and indicator links.
- Lets the manager explicitly select unfinished canonical Tasks to carry into the next-period context.
- Carries Tasks and source Battle Plans **by reference only**; no duplicate task or Battle Plan records are created.
- Does not reset Task due dates, status, result, revision or history.
- Shows the source cycle and carried Tasks in the new cycle.
- Adds explicit calendar cascade creation: Year → Quarter → Month → Week.
- Child cycle dates must be contained by the parent cycle dates.
- No schema, ACL, services or privacy storage changes.
