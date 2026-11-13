# BMS24 RC29 build2026101736 — Battle Plan rollover discovery

Improves discoverability of the **existing** canonical carry-forward workflow on active weekly Battle Plans.

- Highlights the number of unfinished, not-yet-carried items near the start of the weekly overview.
- Adds a jump link to the existing rollover selection form; no duplicate task creation.
- Old plan links, original task IDs, comments, and due dates remain unchanged.
- Uses the existing ACL and rollover action, with no new POST path or DDL.
- No automatic copying or moving of tasks, and no change to closed plans.

Acceptance needed on a Moodle test instance: open an active weekly plan with unfinished items, jump to rollover and select an item, verify next plan references the same task and old plan retains its history.
