# BMS24 1.1.0-rc29 build 2026101655

## Battle Plan — metric actions cleanup

- Removed the generic “Additional metric actions” entry point from the normal Metrics tab because plan/fact actions are already available directly inside each metric card.
- Canonical manager-only metric actions (condition confirmation, management action/task flow) are now available directly in the metric card when the existing indicator-management permission allows them.
- Specialist mode remains reachable only when the Battle Plan really has a pinned legacy quota set; its link is labelled with the existing legacy quota wording instead of a generic duplicate action label.
- No DB schema, ACL/RBAC, privacy, company isolation, or metric calculation semantics changed.
