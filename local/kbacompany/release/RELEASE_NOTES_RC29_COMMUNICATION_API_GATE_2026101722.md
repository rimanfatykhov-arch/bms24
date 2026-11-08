# BMS24 1.1.0-rc29 build2026101722 — Communication API Gate

Base: build2026101721.

- Added release-blocking Moodle 4.0 communication API source-contract gate.
- Whitelists the standard `html_writer` helpers actually used by Communication Center: `div`, `empty_tag`, `label`, `link`, `select`, `tag`. Unrecognized calls fail the build.
- Guards against unsupported bare `string_exists()` and `html_writer::nav()`.
- Ensures message search GET, canonical composer POST, history viewport, and navigation are present.
- Added accessible aria labels for message area and conversation sidebar.
- No DDL, ACL, messages, mark-read, company isolation or canonical write path changes.

QA: source-level gate only; live Moodle acceptance NOT RUN.

Live checks: open communication center, switch tabs/threads, send, search, scroll, create conversation, older/newer pages; KBA and Boost; role and company isolation.
