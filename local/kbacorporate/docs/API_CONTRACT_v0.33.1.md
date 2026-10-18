# API CONTRACT — Implementation evidence v0.33.1

## Public API status

This release does not add a Moodle external-service endpoint or a portable JSON contract for evidence. The feature is exposed through authenticated Moodle pages and internal PHP service methods only.

## Internal service contract

Class: `local_kbacorporate\local\implementation_evidence`

Read methods:

- `is_available()`
- `get_task(int $taskid)`
- `get_record(int $evidenceid)`
- `get_versions(int $taskid)`
- `get_latest(int $taskid)`
- `get_summary(int $taskid)`
- `get_history(int $taskid)`
- `get_files(int $evidenceid)`

Write methods:

- `submit(int $taskid, int $userid, string $summary, string $externalurl, int $draftitemid)`
- `review(int $evidenceid, int $userid, bool $accepted, string $reviewcomment)`

Callers must pass the authenticated actor and must not bypass page access, session-key and capability checks. External integrations should continue reading task compatibility fields until a dedicated stable evidence API is released.
