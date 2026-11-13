# BMS24 RC29 — build2026101708 — Unit recurring management patterns

Base: 2026101707. Contract: Weekly Management Coordination 0.8.1.

Existing unit cards now show transparent repeat-signal labels and matched weekly dates for each unit, using the SAME 4/8 cached-in-request overview reads as the history view. Five canonical management pattern rules are reused, with no new severity score, ranking, write path, action registry or DDL. A unit with no records in a given week contributes no measured coverage data; a missing week must not fabricate a percentage. The selected employee history scope is separate from unit summaries. Unit cards remain links to the existing coordination unit filter (canonical company/manager checks), not direct writes.

Static QA required via canonical build_release.py. Live Moodle acceptance NOT RUN. Check manager visibility, 4/8 weeks, known and unassigned units, cross-company route access, mobile and query performance. No PHPUnit or browser runtime acceptance claimed.
