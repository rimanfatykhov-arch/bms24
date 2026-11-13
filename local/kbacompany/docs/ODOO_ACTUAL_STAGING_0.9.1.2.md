# BMS24 Odoo Actual Read Staging / Provenance — 0.9.1.2

This slice reads one candidate operational actual from an already human-mapped Odoo record and builds a read-only provenance packet. It does not change canonical BMS fact history.

## Indicator source contract

The canonical indicator remains `local_kbacompany_stat`. Its latest active passport must use `sourcekind=integration` and `sourceconfig` JSON:

```json
{
  "provider": "odoo",
  "environment": "prod",
  "mode": "mapped_record_field",
  "value_field": "x_bms_actual",
  "source_timestamp_field": "write_date",
  "label_field": "display_name"
}
```

The indicator must also have an active, human-confirmed Odoo mapping in `local_kbacompany_extmap`. The mapping supplies `externalmodel` and a positive numeric Odoo record ID.

## Staging packet

The CLI reads only the configured fields through Odoo JSON-2 `read` and returns:

- candidate value or null;
- source timestamp;
- exact BMS Company / Indicator / period identity;
- mapping UUID, Odoo model and record ID;
- source-config revision;
- provenance hash;
- deterministic idempotency key;
- current canonical fact value/revision, read-only;
- conflict state: missing / no existing fact / same value / different value.

Missing actual is never converted to zero.

## CLI

```bash
BMS_ODOO_BASE_URL='https://example.odoo.com' \
BMS_ODOO_API_KEY='***' \
BMS_ODOO_DATABASE='optional' \
php local/kbacompany/cli/odoo_actual_stage.php \
  --company='<BMS UUID>' --environment='prod' \
  --indicator='<indicator UUID>' --period='2026-09-01'
```

## Explicitly not included

- no persistence of staged candidates;
- no `local_kbacompany_statval` insert/update;
- no automatic indicator fact update;
- no Odoo create/write/unlink;
- no cron/background sync;
- no secret storage;
- no aggregation over arbitrary Odoo transactions;
- no human-confirmed import action yet.

A later import slice must re-read/revalidate provenance, protect against replay/conflict, and require explicit human confirmation before calling the canonical BMS fact writer.
