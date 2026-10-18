# KBA Corporate Dashboard 0.17.7

Build: `2026090206`  
Date: 2026-09-02

## Added

- The role-template library shows the template version and the version currently applied in the selected company.
- Every template is marked as **Up to date**, **Update required** or **Role not created**.
- An outdated company role can be updated directly from the template library after explicit confirmation.

## Safety

- The update is restricted to the selected company and protected by Moodle capability, company access and `sesskey` checks.
- Template-controlled competency requirements are refreshed and new requirements are added.
- Manual competency overrides, company-only requirements and employee role assignments remain unchanged.
- No competency requirement is silently removed.

## Upgrade

Install the complete ZIP over the existing plugin without uninstalling it. No database structure changes are made. Purge Moodle caches after upgrading.
