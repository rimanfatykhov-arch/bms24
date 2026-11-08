# local_kbacompany 1.1.0-alpha13.1 build2026101203

## Calendar Meeting Finder URL Hotfix

Fixes the Moodle 4.1 runtime coding error `Url parameters values can not be arrays!` raised by Calendar Meeting Time Finder when generating the prefilled BMS Meeting URL.

### Fix
- `calendar_workspace_service::meeting_finder_model()` no longer passes an array to `moodle_url`.
- Participant IDs are encoded in scalar `prefillparticipantscsv`.
- `meetings.php` safely parses the CSV into positive integer IDs and then applies the existing company employee allowlist before preselecting participants.
- Existing `prefillparticipants[]` requests remain accepted.

### Scope
Hotfix only. No DB schema, ACL, privacy, Calendar CRUD, Meeting service, notification engine, or canonical data changes.
