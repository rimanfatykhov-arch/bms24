# KBA Corporate Dashboard 0.17.6

Build: `2026090205`  
Date: 2026-09-02

## Added

- Site administrators can save an existing company role as a global role template.
- The role name, code, description, active state and complete competency target profile are copied.
- The new template is independent and starts at template version 1.
- The source company role and all employee role assignments remain unchanged.
- A global-code conflict stops the operation with an explicit message.

## Upgrade

Install the complete ZIP over the existing plugin without uninstalling it. No database structure changes are made. Purge Moodle caches after upgrading.
