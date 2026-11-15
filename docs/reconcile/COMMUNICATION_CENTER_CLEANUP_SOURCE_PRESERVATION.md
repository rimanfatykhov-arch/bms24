# Communication Center cleanup: exact source updates in the PR53/PR56 preservation gate

Bounded review of the four protected files changed by the Communication Center cleanup.
It is **not** runtime acceptance, owner approval, or permission to publish.

Exact before/after LF-normalized SHA256 values: `communication-center-cleanup-source-preservation.json`.
`before` is the content at base `5924bc074c2a34d485b18a4fba4f7b7aa9534358` (#113: published #110 + #107 day summary):
for `communication_service.php` and `styles.css` it equals the PR102 reviewed `after`; for both lang files it equals the
#113 `after` in `daily-summary-after110-source-preservation.json`, which itself chains on #110 (`task-open-...json`).
Chain: PR102 -> #110 task opened -> #113 day summary -> this cleanup; no link is reset.

| File | Intentional change | Preservation assessment |
| --- | --- | --- |
| `classes/local/communication_service.php` | `VIEWER_READ_KINDS = ['task']`; non-participant read of a task discussion and its files after object ACL; no read state for readers; unique direct conversation per pair; `leave_thread`, `remove_participant` (groups only); inline `@Full Name` mentions limited to active participants; quoted reply originals (same thread, withdrawn text hidden); four contract rules added. | Existing write paths, participation checks, edit/withdraw audit, attachments, notifications and privacy semantics unchanged. Contract version unchanged. |
| `lang/en/local_kbacompany.php` | 23 strings appended. | Existing strings unchanged; RU/EN key parity retained. |
| `lang/ru/local_kbacompany.php` | Same 23 keys in Russian. | Existing strings unchanged; key parity retained. |
| `styles.css` | Scoped rules appended for quotes, inline task events, reply target, member role, leave/join forms. | Existing rules unchanged. |

The gate adds one more exact-hash entry; no path exemption, skipped checker, manifest, schema or migration change.
`tools/tests/test_pr102_source_preservation.py` chains this follow-up from the PR102 `after` hashes and still
proves that any further unreviewed edit fails.

Merge note: rebased onto #113 `5924bc0`. If #113 changes again (Codex is fixing the dev HTTP 500), this follow-up is
rebased once more and its lang `before` re-chained on the new #113 `after`; old hashes are never carried over.

## Day-summary lang guard (#113) extended, not relaxed

`tools/tests/test_dailyreport_source_preservation.py` (from #107/#113) asserted that everything after the day-summary
marker is exactly its 18 `dailyreport_*` keys, i.e. that the day summary is the last lang block. Appending this cleanup's
23 strings after it is the same append-only pattern, so the test now splits at the next marker and checks each link:
published #110 prefix hash (unchanged), the day-summary block (18 keys, unique, no overrides) **and** the exact #113 file
hash up to the cleanup marker, then the cleanup block (23 keys, unique, allowed prefixes, no overrides of any earlier key).
Negative checks retained and extended: appended bytes, overridden day-summary key, overridden cleanup key, cleanup block
moved in front of the day summary, cleanup key redefining a day-summary key — all fail.
