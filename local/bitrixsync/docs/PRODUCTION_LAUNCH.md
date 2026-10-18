# KBA Connect v5.0.14.2 — Production Launch Pack

This package consolidates the launch path around the **existing real v5 engine**.
It does not use the beta placeholder adapters for the controlled live run.

## Real execution path

Bitrix Deal
→ Deal Context
→ Contact → Moodle User resolution/create
→ Product rows
→ Product → Course resolver
→ Corporate company/license/allocation
→ Moodle manual enrolment
→ CRM writeback/timeline
→ local_bitrixsync_dealrun audit

## Important production fixes in this pack

1. `course_resolver` now falls back to `local_bitrixsync_coursemap`.
   This is required for Deal 3761 product IDs 235 / 237 / 227 → courses 8 / 2 / 13.

2. Automatic v5 queue traffic is stage-filtered by `v5_allowed_stages`.

3. Manual REAL testing is restricted to `controlled_test_deal_id` (default 3761)
   and requires exact confirmation `EXECUTE_3761`.

4. History/Timeline pages use the real `local_bitrixsync_dealrun` audit.

## Recommended launch order

1. Install/upgrade package.
2. `php local/bitrixsync/cli/production_schema_check.php`
3. `php local/bitrixsync/cli/production_preflight.php --dealid=3761`
4. `php local/bitrixsync/cli/production_dryrun.php --dealid=3761`
5. Review `/local/bitrixsync/history.php`
6. REAL controlled test only after all preflight checks are green:
   `php local/bitrixsync/cli/production_execute.php --dealid=3761 --confirm=EXECUTE_3761`
7. Review `deal_runs.php`, History, Timeline and Bitrix/Corporate/Moodle results.
8. Refresh Production Readiness.
9. Only then configure allowed stages and enable automatic v5 inbound.
