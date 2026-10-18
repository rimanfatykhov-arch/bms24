# KBA Connect v5.0.26 — Async CRM Field Sync

## Problem

The CRM Fields page could appear to hang when the administrator clicked
"Создать отсутствующие / синхронизировать".

The page was performing Bitrix24 REST userfield discovery and creation for
Company, Contact and Deal inside one browser request. On a portal where Company
fields already existed but Contact and Deal fields were missing, this could
require many sequential remote calls and exceed a normal web request time.

## Solution

CRM field synchronization is now background-only.

Browser action:
- enqueue Company;
- enqueue Contact;
- enqueue Deal;
- return immediately.

Dedicated Moodle cron task:
- processes exactly one CRM entity per cron execution;
- updates the existing CRM field resolution cache;
- records errors in the existing KBA Connect sync queue.

## UI

CRM Fields now shows:
- entity;
- requested operation;
- Pending / Processing / Completed / Failed;
- attempts;
- last error.

While jobs are active the page auto-refreshes every 8 seconds.

## Important

CRM field setup is processed independently of the normal automatic-deal queue
switch. This allows the administrator to configure Bitrix24 before production
deal processing is enabled.

## Support CLI

Status:

`php local/bitrixsync/cli/crm_field_sync_status.php`

Process one entity immediately:

`php local/bitrixsync/cli/process_crm_field_sync.php`
