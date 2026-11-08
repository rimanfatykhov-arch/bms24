# RC29 build2026101730 — Global App Shell Navigation Route Hotfix

## Root cause
`bms_workspace.php` and `bms_architecture.php` render the theme / owned shell independently and do not call `bms_page_shell_service::begin()`. Build2026101729 registered the compact-sidebar AMD only within that shared adapter, so these two routes missed it.

## Fix
Register `local_kbacompany/bms_shell_navigation` in both autonomous controllers before output, preserving existing shell selection, routing, ACL, and page body. Other pages retain the shared adapter registration. A release-blocking source check verifies all three entry paths and rejects duplicates or late JS registration.

## Acceptance
Navigate across Workspace, Architecture, and Communication Center in compact state. Verify that toggle appears in both theme and owned shell, state is preserved and text does not wrap by character. Live Moodle acceptance has not been performed.

No schema changes or communication business-logic changes.
