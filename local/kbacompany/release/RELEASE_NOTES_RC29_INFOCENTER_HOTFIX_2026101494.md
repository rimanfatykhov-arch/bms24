# RC29 build2026101494 — Information Center hotfix

- Resolve `Invalid state passed to moodle_page::set_state` by pairing `bms_page_shell_service::begin()` and `::finish()`; no direct second `$OUTPUT->footer()`.
- Read-only Information Center displays only indicator(s) explicitly flagged `Главная метрика компании` in canonical `indicator_registry_service`.
- When none is selected, show a link to configure metrics. When multiple flagged, show warning instead of silently choosing.
- No duplicate stat records or database schema changes; current plan/fact and history from canonical register.
- PHP lint and ZIP checks are static only; Moodle browser acceptance pending.
