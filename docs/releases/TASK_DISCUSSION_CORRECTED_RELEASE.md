# Task discussion corrected release (successor to #100)

Status: HOLD; implementation candidate, not accepted or deployed.

Base package source: 01af6405bcb7db73f67cb45fee837ba97b8c6ed3. This branch includes the accepted #100 UI plus a new functional change; prior package hashes and PASS results do not certify it.

## Confirmed defect and correction

A historical task creator who is no longer a current company employee was automatically invited into the first discussion. Communication Center correctly rejected that invitation, preventing an otherwise authorised reviewer from posting. Reproduced on the isolated restored production dataset before the change; the same transactional probe succeeds with eligible participants only. Private identifiers and customer data remain outside Git.

Task role/history records remain unchanged. Candidate participants must have current eligibility and task access; company/task/message/file permission checks remain enforced. Only expected access-denial exceptions are filtered. Discussion creation commits under its own lock before the atomic message/attachments transaction. An invalid upload can leave an empty discussion, as before, but must not leave a partial message or attachment set.

## Unified scope

- Multiple selected files accumulate; individual removal, sizes, retained draft on failure, one message with protected attachments.
- No standalone Recent activity panel; the existing History tab and records remain.
- Actions/Tools overlays in owned and legacy shells; viewport bounds, internal scroll, Escape/outside/action close and keyboard focus.
- Existing #100 button/layout fix retained.

No schema, Moodle Core, Corporate replacement, role expansion, production-data repair or migration. Numeric plugin/schema version remains unchanged; source SHA/tree and immutable ZIP/manifest identify this code-only candidate. Do not equate packages by numeric version.

## Evidence and remaining gates

Local PHP lint 546 files, RU/EN parity 9103 keys and XML PASS. Browser contract 27 tests (normal and optimized Python), merge gate 24 tests PASS. These are not release acceptance.

Initial isolated menu negative reproduction: Escape did not close the menu. Initial overlay passed 18 menu cases across nine widths, with 18 matching loaded CSS observations. Expanded menu and multi-file browser acceptance is still pending; earlier harness/runtime failures remain recorded. New ACL PHPUnit tests and full dual-engine BMS/Corporate suites require current-candidate execution. BROWSER now requires the multi-file scenario, including malformed/missing/failed evidence rejection.

Mandatory before READY: exact candidate CI/FULL/security; new and existing browser cases on isolated Moodle; actual owner-task reproduction, foreign/revoked/no-task-access denials; all uploaded files reloaded/downloaded with SHA256; menu geometry/click/focus at 320–1440; history; normal PHP limits restored; exact-package install/rollback/reinstall; baseline/dependency drift and backup freshness.

Replay receipts retain the existing session-local 2-hour/40-entry bound. They do not promise durable exactly-once across session loss. No silent retry after ambiguous transport failure.

## Publication and rollback

Production remains on the prior code after the #100 code-only rollback. No new publication is authorised until acceptance and a fresh explicitly agreed pause window. Retain Corporate and old backup/key; storage policy remains production plus test. Never overwrite newer database/moodledata records during rollback. For this no-schema package, retain previous plugin code, swap only the approved plugin, purge caches, perform agreed smoke, and restore the previous code if criteria fail. Restore cron and maintenance to their captured states. A DB/data rollback needs a separate justified plan.

No merge, staging changes, branch protection changes, or GitHub Release publication.
