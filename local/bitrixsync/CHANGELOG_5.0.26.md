# KBA Connect v5.0.26

## Async CRM Field Sync

Fixed:
- CRM Fields UI hanging during synchronous Bitrix24 field provisioning.

Changed:
- CRM field check/provision actions now enqueue background jobs;
- added dedicated one-entity-per-cron CRM field worker;
- added background progress table to CRM Fields UI;
- added automatic lightweight page refresh while jobs are active;
- added explicit failed-state/error visibility;
- refresh-only CRM check is also backgrounded;
- normal deal queue may remain disabled during CRM setup.

Added:
- `crm_field_sync_service`;
- scheduled task `process_crm_field_sync`;
- CLI status command;
- CLI one-shot CRM field worker.
