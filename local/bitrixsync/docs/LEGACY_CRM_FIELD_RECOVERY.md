# KBA Connect v5.0.29 — Legacy CRM Field Recovery & Diagnostics

## Observed problem

On the CRM Fields page:
- Company fields are already green;
- Contact and Deal remain missing;
- background Contact / Deal jobs can remain in `processing`.

This installation previously used v0.4.6, which created many fields with
labels beginning with "Moodle" and XML IDs such as:

Contact:
- KBA_MOODLE_USER_ID
- KBA_MOODLE_LOGIN
- KBA_MOODLE_PROFILE_URL

Deal:
- KBA_MOODLE_ENROL_STATUS
- KBA_MOODLE_LAST_SYNC
- KBA_MOODLE_SYNC_ERROR

## Root compatibility issue

The v5 field resolver understood exact Russian labels but did not correctly
read Bitrix24 localized label arrays such as:

`{"ru":"Moodle User ID"}`

It also did not explicitly recognize legacy v0.4.6 XML IDs / FIELD_NAME
suffixes.

## Fixes

- localized label arrays are parsed correctly;
- v0.4.6 XML IDs are recognized;
- v0.4.6 FIELD_NAME values (`MBA_M_*`) are recognized;
- old saved Moodle `fieldmap` can be imported instantly without REST calls;
- the UI shows the source used for every resolved field;
- Bitrix userfield pagination uses `next`, detects repeated pages, and has a
  safety limit;
- CRM field jobs stuck in `processing` for over 3 minutes become `failed`
  with a visible explanation;
- administrators can reset stale jobs and retry failures;
- scheduled CRM workers use a Moodle lock to prevent overlap.

## Recommended recovery

1. Install v5.0.29.
2. Open CRM Fields.
3. If "Существующие поля Moodle из старой версии" is shown, click:
   `Использовать существующие поля v0.4.6`.
4. Click `Проверить поля в фоне`.
5. Only after existing fields have been recognized, click:
   `Создать только отсутствующие`.

This prevents duplicate Moodle fields in Bitrix24.
