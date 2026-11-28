# RC33 PR-1 — shared communication write gate and notification eligibility

Status: implementation candidate, **acceptance HOLD** until Moodle PHPUnit on MariaDB and Percona and the parallel
harness of PR-3 pass. First written on `main` 62fdc4934b8b8a87fb8c7f21a52c56b64229e836; now synced with `main`
b78ad00bcf522ef8c9e7231256d2ffea2753a627 (BDO-1 #206 after terminology #187), plugin 2026112400 (R5a), release 1.1.0-rc29.
Executor: Claude.

## Decision and scope

Owner decision D3 (24.11.2026, PR #88 / issue #75) allows narrow changes to the canonical notification eligibility
path and to the shared revocation protocol (ADR-0006 writers), each with regression tests. On 24.11.2026 the owner
approved splitting the MAX fake-provider slice into a stack. This is PR-1. It has no inbound code, MAX schema or service.

Contract `docs/architecture/RC33_MAX_FEED_INBOUND_CONTRACT.md` §6.4 (branch `design/agent5-rc33-max-feed-contract`)
requires every revocation writer to share one lock protocol with the publishing path. Before this change the
send path took no lock, participant writers took none, and revocation took only `member:{company,user}`.

## Change

- `communication_write_gate`: one re-entrant company lock under the existing key `communication:{company}`.
  Fixed order: object locks (`taskdiscussion:*`, `bplandiscussion:*`) → `member:*` → `communication:{company}` →
  `commmsg:*`.
- Under the gate: `create_thread`, `add_participant`, `append_message`, `send_message_with_attachments`,
  `leave_thread`, `remove_participant`, `change_message`. Author access, participation, thread state and recipients
  are checked while holding it. `add_participant` and `remove_participant` are now transactional.
- Revocation: `membership_access_service::change` takes the gate after the member lock and holds it through its commit.
  The Bitrix closure of other-company memberships takes the gate of each affected company.
- Task and Battle Plan discussion posts take the gate before their own outer transaction. The gate is held
  through the outermost commit.
- Notification eligibility: a message notification goes only to a recipient with a live account (not deleted or
  suspended) and current company access (`company_context`, which includes the ADR-0006 deny). The existing
  object-ACL check remains. A suspended author cannot send. `add_participant` refuses an ineligible user.
- `send_message(..., $inlinementions = false)` keeps `@Full Name` text literal. The default is unchanged.

## N28: object ACL writers under the same gate (COORD-D05)

The owner approved option A in #205 (COORD-D05): object ACL writers of tasks, Battle Plans and meetings commit under
the communication gate, and the write right is re-checked under the gate for every message.

Gated writers. Each takes the gate after its own object lock and before its transaction, and releases it in
`finally` before its own lock:

| Service | Writers | Own lock before the gate |
|---|---|---|
| `task_service` | `classify_duty`, `save`, `set_observer`, `transition`, `delete_task` | `taskcenter:{company}` (`transition` of a parent) → `task:{company}:{task}` |
| `battle_plan_service` | `set_observer`, `remove_task`, `save_settings`, `transition`, `review` | `battleplan:{company}:{plan}` |
| `position_coverage_service` | `transition` | `position_coverage:{company}` |
| `meeting_service` | `create`, `add_participant` | `meetingresource:*` (booking), `meeting:{company}` (`create`) |
| `access_role_service` | `set_grant`, `assign` | none |

`meeting_service::add_participant` now re-checks the organizer, the meeting state and the new user's employment under
the gate and inserts the participant and the thread member in one transaction.

Write right under the gate: `communication_service::append_message_locked` calls the canonical
`task_service::can_post_discussion_for` and the new `battle_plan_service::can_post_discussion_for` for object threads
of kind `task` and `battleplan`, like the R3 daily report check. So the generic send path and the object post path
both refuse a user whose right was removed, also when their thread membership row is still active.

Lock order after N28:

0. (PR-2) `inbsrc:{key}`
1. object locks: discussion locks (`taskdiscussion:*`, `bplandiscussion:*`, `dreportdiscussion:*`) or ACL writer
   locks (above). A discussion lock and an ACL writer lock are never held together.
2. `member:*`
3. `communication:{company}` (the gate)
4. `commmsg:*`

A static audit found no named lock taken inside a gated region and no caller that holds the gate when it calls one of
these writers. `tools/tests/test_communication_write_gate_protocol.py` pins this in source.

Linearization: a revocation commits either before a send's access check, and the send fails, or after the send
commits. Already published messages are not removed.

## Residual risks, not closed here

- N28 residuals (option A covers the narrowing writers only):
  - writers that only widen access are not gated: `task_service::create`, `link_subtask`, Battle Plan `create_plan`,
    `add_task`, `rollover_open_tasks`. Meeting `save` and `transition` change no participants;
  - organization structure writers (`organizing_board_service`, `design_service`, `position_service`) change department scope and are not
    gated. Department-based access is still re-checked under the gate at send time, but their commit is not
    linearized with a send;
  - `management_cycle_template_service::checkpoint_meeting` calls `meeting_service::create` inside its own
    `mcycle-write` lock and transaction. The gate is released before that outer commit. This only widens access.
    Taking the gate in the caller would invert the order against `meeting:{company}`;
  - `battle_plan_week_transition_service` and `task_batch_actions_service` call gated writers in loops. Each call
    holds the gate for its own commit only;
  - the gate is company-wide, so ACL writes and sends of one company now queue behind each other. Throughput has not
    been measured.
- `company_registry_service` duplicate merge, privacy erasure of membership rows and Moodle core user suspension do
  not take the gate. Suspension is enforced as an eligibility check at send time.
- If `upsert_external_employee` runs inside a caller's transaction, the gate is released before that caller commits.
- Each message now resolves company access per recipient. The cost on large groups has not been measured.

## Tests

- `local/kbacompany/tests/communication_write_gate_test.php` (added to the STANDARD allowlist): re-entrancy and
  release; failed send writes nothing; a revoked author cannot send or edit while the other company still works;
  revoked, suspended and deleted recipients get no notification; a suspended author cannot send; a revoked user cannot be added;
  a removed member is not notified; literal inline mentions. N28: a removed task observer and a removed Battle Plan
  observer cannot post on the object path or the generic send path; a deleted task closes its discussion for writers;
  a failed ACL writer (stale revision) releases the gate and changes nothing; a meeting participant is added
  atomically and a user of another company is refused. The gate is free after every call.
- `tools/tests/test_communication_write_gate_protocol.py`: static lock-protocol guard. Not a concurrency proof.
- Not covered here: parallel processes (send racing revoke, N21/N28), MariaDB/Percona runs. The D4 harness in
  PR-3 (#182) has the negative N28 races for tasks, Battle Plans and meetings; it runs only when the stack targets `main`.
