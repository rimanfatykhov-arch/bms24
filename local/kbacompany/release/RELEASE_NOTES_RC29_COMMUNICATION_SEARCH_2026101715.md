# RC29 build2026101715 — Communication Center search and unread mention

- Added GET search for the selected participant-authorized thread messages, with matching count, empty state and reset link. No new search index or persistence.
- Sidebar mention badge now requires an active mentioned message ID strictly after the canonical read marker in that thread; historical mentions no longer look unread.
- Read state still belongs exclusively to communication_service; opening a thread may mark it read according to existing behavior.
- No DDL, writes on GET beyond existing canonical thread-open behavior, new role permission, or parallel messaging model.
- Search applies to the already loaded canonical message projection; high-volume thread paging is a separate future enhancement.
- Static source tests are not live functional tests. Live Moodle acceptance NOT RUN.
