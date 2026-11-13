# RC29 build2026101721 — Communication Center Moodle HTML writer hotfix

Base: build2026101720.

## Fix

- Replaced both unsupported `\html_writer::nav()` invocations with Moodle 4.0-compatible `\html_writer::tag('nav', ..., attrs)`.
- Maintained semantic `nav` landmark, classes and accessible labels for tabs and message paging.
- Added release-blocking regression assertion disallowing unsupported navigation helper in Communication Center.
- No DDL, no write-path changes, no ACL/RBAC changes.

## Acceptance required

Clear Moodle cache after installing; open Communication Center, switch tabs, open a conversation with >30 messages, check Earlier/Later and message send, including narrow screen. Live Moodle acceptance NOT RUN.
