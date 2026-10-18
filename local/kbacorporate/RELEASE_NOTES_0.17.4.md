# KBA Corporate Dashboard 0.17.4

Release: **Business Role Template Library**  
Build: `2026090201`  
Minimum Moodle: 4.0  
Public API: 1.15 (unchanged)

## Workflow

Global KBA business roles form the centrally maintained template library. An authorised company manager opens **Business roles → Role template library** and creates an independent role for the selected company.

The copied company role contains the template name, code, description and complete competency profile. It records its source template and applied version, but it is not live inheritance: the company can safely adapt its copy.

## Update rules

- Requirements still marked `template` are refreshed from a newer template.
- New template requirements are added.
- Requirements manually changed by the company are preserved.
- Company-only requirements are preserved.
- No competency requirement is silently removed.
- Employee role assignments are never changed by template operations.

Site administrators maintain global templates. Corporate managers can view them, create company copies and manage only their authorised company roles.

## Upgrade

Install the complete ZIP over the existing plugin without uninstalling it. Moodle runs savepoint `2026090201` and adds three non-destructive fields to `local_kbacorp_busrole`.
