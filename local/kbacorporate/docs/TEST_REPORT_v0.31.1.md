# TEST REPORT — v0.31.1

## Static release gate

- ZIP root target: `kbacorporate/` — PASS
- `version.php` build > v0.31.0 — PASS (`2026090607` > `2026090606`)
- `lang/en` present — PASS
- `lang/ru` present — PASS
- new integration language references resolved in EN/RU — PASS
- `install.xml` updated for schema change — PASS
- `install.xml` XML parse — PASS
- `db/upgrade.php` upgrade step `2026090607` — PASS
- PHP syntax check — PASS (194 PHP files at pre-package gate)
- destructive migration — NONE
- existing KBA development rows rewritten during upgrade — NO
- migration/sync operation designed to be repeatable via persisted IDs + stable description markers — PASS by static review

## Moodle core contract reviewed

The integration uses standard `core_competency\api` operations for plans/templates and competency membership. Moodle core remains authoritative for standard Learning Plan objects; KBA records hold corporate extension metadata and links.

## Runtime regression to confirm in Moodle 4.0 pilot

1. Upgrade from v0.31.0.
2. Confirm fields `moodletemplateid` and `moodleplanid` are created.
3. Open `/local/kbacorporate/moodle_learning_plans.php?companyid=<id>`.
4. Run company synchronisation as site admin/authorised competency manager.
5. Confirm standard Moodle templates are created/linked for active business roles.
6. Confirm standard Moodle plans are created/linked for open KBA development plans.
7. Re-run synchronisation and confirm duplicate templates/plans are not created.
8. Open Role Learning Matrix and confirm plan membership is read from Moodle core when linked.
9. Open KBA development plan and confirm direct link to `/admin/tool/lp/plan.php?id=<id>`.
10. Add/remove one KBA development competency and confirm standard plan membership synchronises when the current actor has core plan-manage permission.
11. Confirm existing KBA plan metadata, course recommendations and GAP data are unchanged.

## Known limitations

- Runtime Moodle 4.0 regression is pending installation/pilot confirmation.
- Moodle core plan review/approval state does not replace the existing KBA employee agreement workflow in v0.31.1. New standard Moodle plans are created active; KBA workflow remains an extension layer.
- An authorised synchronising user must also have the applicable Moodle core competency template/plan capabilities. Errors are isolated and reported on the integration page.
- Rollback does not automatically delete Moodle core templates/plans created after synchronisation.

**Runtime status:** awaiting installation/pilot confirmation.
