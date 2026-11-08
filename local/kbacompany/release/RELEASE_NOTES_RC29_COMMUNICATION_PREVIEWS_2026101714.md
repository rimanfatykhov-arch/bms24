# RC29 build2026101714 — Communication sidebar previews

- Added short last-visible-message snippets in conversation list.
- Added existing unread count emphasis and explicit per-thread mention marker.
- Preview content is retrieved only for already visible threads with active participant join, scoped to company and active messages.
- Safe escaped UI rendering. No GET side effects, new message entities, new permissions, new DDL or write paths.
- Bounded preview work (100 threads / 2000 rows per projection). This is an initial performance guard; high-volume coverage needs live profiling.
- Live Moodle acceptance NOT RUN.
