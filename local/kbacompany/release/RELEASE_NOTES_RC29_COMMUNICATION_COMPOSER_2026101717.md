# BMS24 RC29 build2026101717 — Communication Center usability hotfix

- Moodle live screenshot: search box displays `[[communicationcenter_search_messages]]` despite RU/EN keys being packaged. Added guarded fallback if Moodle language cache is stale; purge caches on deployment.
- Composer had oversize always-visible mention multiselect and attachment field pushing Send below the viewport. These optional native POST fields are now inside accessible native details/summary with unchanged names and canonical submit.
- Constrained scrollable message list, kept composer inside the central panel with readable spacing. No DB paging yet.
- No schema, task/communication write service, access or business model changes. Static QA only; live Moodle acceptance NOT RUN.
