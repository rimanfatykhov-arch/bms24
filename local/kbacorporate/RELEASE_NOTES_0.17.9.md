# KBA Corporate Dashboard 0.17.9

Build: `2026090208`  
Date: 2026-09-02

## Added

- Every global role-template version stores an immutable role and competency-profile snapshot.
- Version history records the version number, date, author and optional change comment.
- Site administrators can compare a version with its predecessor and see added, changed and removed competency requirements.
- Before updating a company role, managers can preview additions, template-controlled changes, protected manual overrides and requirements retained because they are absent from the new template.
- Existing global templates receive a snapshot of their current version during upgrade.

## Data model

- `local_kbacorp_rolever` stores template-version metadata.
- `local_kbacorp_rolevercomp` stores the competency-profile snapshot.
- The version author is covered by Moodle Privacy API and is anonymised when required without deleting operational history.

## Safety

- Version comparison is read-only.
- A preview never changes the company role.
- Applying an update still requires explicit confirmation and `sesskey`.
- Manual overrides, company-only requirements and employee assignments remain unchanged.
- Requirements removed from a global template are reported but not silently deleted from company roles.

## Upgrade

Install the complete ZIP over the existing plugin without uninstalling it. Moodle creates and backfills the two version-history tables. Purge Moodle caches after upgrading.
