# TEST REPORT — Deal 3761

## Step 1 — Dry Run
Open `/local/bitrixsync/production_control.php`, use Deal ID 3761 and click **Диагностика / Dry Run**.

Expected:
- Contacts = 2
- Contact 2921: email `artur198@inbox.ru`, existing Moodle User 40, decision `ALREADY / MATCHED`
- Contact 12657: email `altreid.torg@bk.ru`, Moodle User 0, decision `WOULD CREATE`
- Courses in deal = 4
- Dry Run performs no writes

## Step 2 — REAL
Only after Step 1 succeeds, check the confirmation box and run REAL.

Expected for Contact 12657:
- decision `CREATED`
- Moodle User ID > 0
- 4 course enrollments (or already-existing equivalents)
- Bitrix24 Contact Moodle fields updated

## Rollback
Restore the previous `local/bitrixsync` directory backup if the upgrade does not pass Moodle validation. Database migration only changes plugin configuration switches and is non-destructive.
