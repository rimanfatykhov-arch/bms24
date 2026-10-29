# BMS24 1.1.0-rc29 build 2026101674

## Task Center → Battle Plan context

- Linked Battle Plans are now visible directly below the Task Center detail tabs.
- Each linked plan is a direct return path to the canonical Battle Plan.
- The plan status and human-readable plan label are shown without creating any duplicate relation.
- The context remains visible on every task detail tab, not only inside the collapsed Relations section.
- Existing Battle Plan placement, ACL/RBAC, company isolation and lifecycle rules are unchanged.

## Technical scope

- `classes/local/task_ui_service.php`
- `bms_ui/task_center.css`
- `version.php` / `db/upgrade.php`

No DDL changes.
