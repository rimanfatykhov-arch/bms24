# KBA Corporate Dashboard 0.17.4.1

Build: `2026090202`  
Date: 2026-09-02

## Fixed

- The global role-template form now posts to an explicit URL that retains `scope=global`.
- The form also submits its scope as a hidden validated value, so Moodle themes cannot silently lose the global-template context.
- Cancelling global-template editing now returns to the template library.

## Upgrade

Install the complete ZIP over the existing plugin without uninstalling it. This hotfix makes no database structure changes. After the Moodle upgrade completes, purge caches and create the global template again.
