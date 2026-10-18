# KBA Corporate Dashboard v0.31.1 — Moodle Learning Plan Integration

**Component:** `local_kbacorporate`  
**Build:** `2026090607`  
**Minimum Moodle:** 4.0  
**Classification:** MINOR  
**Status:** Beta / pilot

## Purpose

Move the learning-plan layer onto Moodle core Competency/Learning Plan mechanisms without losing KBA corporate metadata.

## Architecture

Moodle core is now the authoritative store for:

- Learning Plan Templates (`competency_template`);
- Learning Plans (`competency_plan`);
- competency membership of templates/plans;
- standard Moodle plan UI and APIs.

KBA remains the extension layer for data not represented by Moodle core:

- company business role;
- target competency level;
- GAP baseline/current level;
- priority / mandatory flag;
- recommended course and action due date;
- employee agreement workflow;
- BMS context and future Odoo/BMS ERP identifiers.

Business roles are synchronised to Moodle Learning Plan Templates. Individual GAP plans are independent Moodle Learning Plans, because a KBA employee plan may combine gaps from multiple business roles and therefore cannot safely be bound to one Moodle template.

## Database changes

Non-destructive fields added:

- `local_kbacorp_busrole.moodletemplateid`;
- `local_kbacorp_devplan.moodleplanid`.

No existing rows are deleted or rewritten during upgrade.

## Migration

Open:

`/local/kbacorporate/moodle_learning_plans.php?companyid=<id>`

and run **Synchronise with Moodle Learning Plans** under an authorised manager account.

The operation is idempotent and links/creates:

- Moodle Learning Plan Templates for current business roles;
- Moodle Learning Plans for open KBA development plans.

## UI

- Business Roles page links to Moodle Learning Plans integration.
- Role ↔ Competency ↔ Learning Plan ↔ Course matrix reads Moodle plan competency membership when linked.
- KBA development-plan page shows the linked standard Moodle Learning Plan and keeps KBA extension metadata separate.

## Upgrade / rollback

Back up the database before upgrading. Rollback to v0.31.0 requires code rollback plus either leaving the two new nullable/defaulted link fields in place or restoring the pre-upgrade DB backup. No destructive migration is performed.
