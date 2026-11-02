# RC29 build2026101716 — Communication Center viewport & history navigation

- Live screenshot-driven fix: no horizontal conversation-list scrollbar for long titles; ellipsis and safe grid min-width.
- Conversation pane uses bounded viewport height and scrollable messages, keeping the reply composer accessible.
- GET mpage navigation displays 30 of already authorized messages at once and offers older/newer links. Search matches are computed against the same canonical loaded message projection before page slicing.
- IMPORTANT: this is presentation pagination, not SQL/DB-backed pagination. Canonical communication_service currently hydrates full thread history and marks read when opening a thread. Proper cursor-based DB paging remains a separate architectural step.
- No DB schema, write path, business model or ACL changes. Live Moodle acceptance NOT RUN.
