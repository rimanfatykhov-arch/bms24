# BMS24 RC29 — Communication Center 3.2 (build2026101825)

**Plugin:** `local_kbacompany 1.1.0-rc29`\
**Build:** `2026101825`\
**Scope:** notifications and reduced background load on Communication Center\
**Requires:** Moodle 4.0+, existing BMS24 App Shell\
**New DB tables / capabilities:** none

## User-visible improvements

1. A small accessible unread-conversation badge is displayed beside the "Переписки" header. The badge is hidden when there are no unread conversations.
2. The Communication Center browser tab title displays the number of unread conversations, capped at `99+`. It is derived from the *authorized full page projection*, never guessed from the bounded polling window.
3. Notification counters and thread badges continue to refresh after authorized change detection without reloading the whole page.
4. The existing message composer, cursor position, attachment selection, reader scroll position, canonical actions, and context panel remain intact.

## Load optimization

- Probe interval for active interaction stays approximately 25 seconds with random 13% jitter per interval. Clients no longer bunch into a synchronized burst.
- After **5 minutes** of no pointer/keyboard/wheel activity *in the Communication Center*, the visible page reduces polling frequency to roughly 60 seconds.
- Backoff for repeated failures increases with an upper bound of 180 seconds. The page is not polled in a hidden or offline tab.
- Timers are stopped on pagehide and resumed after a back-forward cache restore.
- If the selected conversation belongs to the already-ACL-checked recent participant-thread window, the service reuses the validated entry. Threads outside that window still undergo explicit participant and underlying-object checks.
- The scope remains limited to 60 recent participant threads, 20 notification rows, and the selected thread. This is not an exact global account-wide change feed.

## Server measurement

A read-only CLI script is bundled:

```bash
php local/kbacompany/cli/benchmark_communication_poll_1825.php \
  --uuid=COMPANY_UUID --actorid=TEST_USER_ID --runs=10
```

Run **only on a test copy**. It reports median, p95, maximum duration, optional read-query counts and memory for the `snapshot()` call. It does **not** simulate concurrent traffic or expose message contents.

## Deployment

1. Back up Moodle files and database.
2. Install `INSTALL_local_kbacompany_1.1.0-rc29_build2026101825.zip`.
3. Run the Moodle plugin upgrade and purge all caches.
4. Verify browser tab title and unread badge with a test account.
5. Open two authorized sessions and send messages; verify updating unread count without reloading.
6. Check hidden-tab pause, draft preservation, and back-forward navigation.
7. Run the CLI benchmark on the test environment with a known test user, and repeat under test traffic before any RC30 sign-off.

## Limits / acceptance

- No push notifications, email, WebSocket or guaranteed sub-second delivery.
- No live Moodle 4.1 / PHP 8.1 / MySQL 5.7 test or actual multiuser load measurement was performed by the build process.
- Acceptance is local-only until validated on the target Moodle environment.

## Files changed

- `version.php`
- `db/upgrade.php`
- `communication_center.php`
- `classes/local/communication_center_ui_service.php`
- `classes/local/communication_live_service.php`
- `amd/src/communication_live_ui.js`
- `amd/build/communication_live_ui.min.js`
- `styles.css`
- `cli/check_communication_1824_contract.py` (allow newer version)
- `cli/check_communication_1825_contract.py` (new)
- `cli/test_communication_poll_budget_1825.py` (new)
- `cli/benchmark_communication_poll_1825.php` (new)
- `release/RELEASE_NOTES_RC29_COMMUNICATION_NOTIFICATIONS_2026101825.md`
