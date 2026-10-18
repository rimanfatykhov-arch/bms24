# KBA Connect CLI

Run from Moodle root.

## Safe status

```bash
php local/bitrixsync/cli/status.php
```

No external REST calls.

## CRM field provisioning

```bash
php local/bitrixsync/cli/provision_crm.php --entity=company
php local/bitrixsync/cli/provision_crm.php --entity=contact
php local/bitrixsync/cli/provision_crm.php --entity=deal
```

This is the preferred way to create CRM user fields on CloudLinux hosting.

## Production Readiness

```bash
php local/bitrixsync/cli/readiness.php --refresh
```

Short Bitrix REST timeouts are applied.

## One controlled Deal

Dry Run:

```bash
php local/bitrixsync/cli/run_deal.php --dealid=123
```

Real controlled run:

```bash
php local/bitrixsync/cli/run_deal.php --dealid=123 --real
```

## Queue

```bash
php local/bitrixsync/cli/queue.php
php local/bitrixsync/cli/queue.php --run
```

## Activate v5

Only after Readiness = READY:

```bash
php local/bitrixsync/cli/activate.php \
  --confirm=ENABLE_V5 \
  --enable-writeback \
  --enable-timeline
```

Enable inbound only after Bitrix24 event/automation has been configured.

## Emergency rollback

```bash
php local/bitrixsync/cli/rollback.php --confirm=LEGACY
```
