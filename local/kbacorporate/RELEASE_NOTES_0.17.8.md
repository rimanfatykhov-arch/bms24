# KBA Corporate Dashboard 0.17.8

Build: `2026090207`  
Date: 2026-09-02

## Added

- Site administrators can open **Company usage** for any global role template.
- The rollout page lists every company role created from the template.
- Each row shows the company, company role, applied version, available version, role state and update requirement.
- An outdated company role can be updated individually after explicit confirmation.

## Safety

- The cross-company page is restricted to site administrators.
- Every update verifies that the selected company role belongs to the displayed global template.
- The action is protected by `sesskey`.
- Template-controlled requirements are refreshed while manual overrides, company-only requirements and employee assignments are preserved.
- There is no bulk update action and no silent removal of competency requirements.

## Upgrade

Install the complete ZIP over the existing plugin without uninstalling it. No database structure changes are made. Purge Moodle caches after upgrading.
