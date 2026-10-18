# KBA Connect v5.0.14.3 — Production Activation

The controlled REAL run for Deal 3761 has already proven:
- 3 / 3 product rows resolved;
- 0 failed;
- 0 new enrolments on repeat;
- 3 already processed;
- Corporate allocations are already allocated;
- no duplicate seat consumption.

This package turns that proof into a gated production activation sequence.

## Phase 1 — Finalize core production settings and CRM

`php local/bitrixsync/cli/production_finalize.php \
  --dealid=3761 \
  --stage='C3:EXECUTING' \
  --confirm=FINALIZE_3761`

This command:
- verifies the latest idempotent REAL run;
- provisions/reuses KBA CRM fields for Company / Contact / Deal;
- sets `v5_allowed_stages`;
- enables queue;
- enables CRM writeback and timeline;
- sets engine_mode=v5;
- intentionally keeps inbound webhook OFF;
- refreshes Production Readiness.

## Phase 2 — CRM controlled test

`php local/bitrixsync/cli/production_crm_test.php \
  --dealid=3761 \
  --confirm=CRM_TEST_3761`

Expected:
- `enrolled = 0`
- `already = 3`
- `failed = 0`
- Corporate items remain `already_allocated`
- CRM company/contact/deal statuses become `updated`
- timeline deal/company become `created` or `updated`

Then:

`php local/bitrixsync/cli/production_go_live_check.php --dealid=3761`

Must return `"ready": true`.

## Phase 3 — Configure Bitrix event

Open:
`/local/bitrixsync/production_activation.php`

Copy the generated webhook endpoint into the Bitrix24 Deal event/robot.
Do not publish or share the token.

## Phase 4 — Enable automatic inbound

Only after the Bitrix event is configured:

`php local/bitrixsync/cli/production_enable_inbound.php \
  --dealid=3761 \
  --stage='C3:EXECUTING' \
  --confirm=ENABLE_INBOUND_3761`

## Phase 5 — Queue smoke

`php local/bitrixsync/cli/production_queue_smoke.php \
  --dealid=3761 \
  --confirm=QUEUE_3761`

Because Deal 3761 is idempotent, this is safe: it should create no new Moodle enrolments and no duplicate Corporate allocations.
