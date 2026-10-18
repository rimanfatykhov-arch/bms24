# KBA Connect v5.0.29

## Legacy CRM Field Recovery & Diagnostics

Fixed:
- old v0.4.6 Moodle fields not recognized when Bitrix returned localized label arrays;
- legacy XML IDs and MBA_M_* field names now recognized;
- possible endless/repeated Bitrix userfield pagination;
- CRM background jobs remaining in processing after killed cron/PHP workers;
- overlapping CRM field cron workers.

Added:
- import of the saved v0.4.6 fieldmap without REST calls;
- legacy Moodle field summary in CRM Fields UI;
- field resolution source column;
- automatic stale-job recovery after 3 minutes;
- manual "Reset stuck jobs";
- manual "Retry failed jobs";
- visible queue age and error;
- `crm_legacy_fields.php` CLI diagnostics.

Safety:
- existing Moodle fields are reused before any missing fields are created.
