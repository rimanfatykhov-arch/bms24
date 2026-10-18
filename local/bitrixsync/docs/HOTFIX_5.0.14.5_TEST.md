# Hotfix 5.0.14.5 Test

After upgrade:

## 1. Probe

`php local/bitrixsync/cli/bitrix_crm_rest_probe.php`

Expected:
`"ready": true`

All four checks should be `ok: true`.

## 2. Finalize

Use Moodle Production Setup Wizard, or CLI fallback:

`php local/bitrixsync/cli/production_finalize.php \
  --dealid=3761 \
  --stage='C3:EXECUTING' \
  --confirm=FINALIZE_3761`

## 3. Do not enable inbound yet

Continue only when CRM field provisioning completes successfully.
