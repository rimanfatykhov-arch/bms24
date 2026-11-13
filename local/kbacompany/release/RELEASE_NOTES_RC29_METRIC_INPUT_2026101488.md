# RC29 build2026101488 — Metric input and single point chart

- Fact vs Plan and single-day period explained in form.
- Date defaults to today (server-local; can be changed before saving).
- A repeated date + kind is treated as a revision, not an addition. Backend still requires a correction reason.
- The chart displays isolated fact or plan observations, including a single period; tooltip for each.
- Existing data remains unchanged. Two repeated entries require checking revisions, not necessarily deletion.
- Live Moodle tests not run.
