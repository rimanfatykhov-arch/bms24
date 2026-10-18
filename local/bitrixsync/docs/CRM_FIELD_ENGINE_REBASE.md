# KBA Connect v5.0.31 — CRM Field Engine Rebase

## What was actually wrong

Comparison against the working v0.4.6 code found three regressions.

### 1. PHP compatibility regression

v5.0.29 used `str_ends_with()` while the production PHP version does not
provide that PHP 8 function.

Observed production error:

`Call to undefined function local_bitrixsync\services\str_ends_with()`

v5.0.31 uses a PHP-7-compatible suffix check.

The Message Template service also no longer uses `str_starts_with()`.

### 2. Pagination regression

Working v0.4.6 `bitrix_client::call_all()` continued only when Bitrix24
explicitly returned `next`.

Later v5 code added an assumption:
"if the page contains 50 rows and there is no next, manually add 50 to start".

On this Bitrix24 portal that produced the same page again for Contact/Deal,
which the safety detector correctly reported as:

`Bitrix24 повторяет одну и ту же страницу CRM-полей`

v5.0.31 removes that assumption and restores the original v0.4.6 contract:
**no `next` = end of list**.

### 3. REST transport drift

Working v0.4.6 always called CRM userfield APIs as:

- `<method>.json`
- JSON request body

Later v5 used form-urlencoded first and JSON only as an error fallback.

CRM field list/add/get now explicitly use the proven old transport again.

## Existing Moodle fields

The legacy v0.4.6 field map continues to be reused before anything new is
created.

The actual type from the old map is preserved. This matters for the old
`KBA_MOODLE_ENROL_STATUS` enumeration.

v5 CRM writeback now translates:

- completed → `Зачислен`
- processing → `Ожидает зачисления`
- error / partial → `Ошибка`

and sends the Bitrix enumeration ID when the reused field is an enumeration.

## Upgrade behavior

Old failed/processing CRM-field queue jobs produced by the broken engine are
removed. Field maps and all business/production data remain intact.

## Verification

Run:

`php local/bitrixsync/cli/crm_field_transport_test.php`

Then in CRM Fields:

1. Use existing v0.4.6 fields.
2. Check fields in background.
3. Create only genuinely missing fields (expected now: Contact position and
   last login if they did not exist before).
