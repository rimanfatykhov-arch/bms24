# BMS24 RC29 build2026101719 — Communication history DB paging

- Canonical communication_service::context accepts an optional bounded history projection for Communication Center; other clients retain the previous interface.
- SQL count and DESC page of 30 messages, chronological display within the selected page. Page 0 is the latest 30; older/newer navigation does not hydrate the entire thread.
- Searching uses Moodle SQL LIKE with escaped user input and exact company/thread scope; active messages only for search.
- Existing participant and object-access checks run before any history query; existing mark_read() semantics preserved.
- No new tables or writes. Current message cards still hydrate mentions/files only for 30 selected rows.
- Static source/lint/package QA only; live Moodle ACL, MySQL 5.7 and UI acceptance NOT RUN.
