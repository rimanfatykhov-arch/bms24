# RC33 PR-1 — shared communication write gate and notification eligibility

Status: implementation candidate, **acceptance HOLD** until Moodle PHPUnit on MariaDB and Percona and the parallel
harness of PR-3 pass. Base: `main` 62fdc4934b8b8a87fb8c7f21a52c56b64229e836, plugin 2026112301, release 1.1.0-rc29.
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

Linearization: a revocation commits either before a send's access check, and the send fails, or after the send
commits. Already published messages are not removed.

## Residual risks, not closed here

- Object-ACL writers outside communication (task, Battle Plan, meeting revisions) are not serialized with the gate.
  Object ACL is re-checked at send time only. This is a residual N28 race; the owner has not yet decided whether it is acceptable.
- `company_registry_service` duplicate merge, privacy erasure of membership rows and Moodle core user suspension do
  not take the gate. Suspension is enforced as an eligibility check at send time.
- `meeting_service` calls `create_thread` and `add_participant` inside its own transaction without holding the gate
  through that commit.
- If `upsert_external_employee` runs inside a caller's transaction, the gate is released before that caller commits.
- Each message now resolves company access per recipient. The cost on large groups has not been measured.

## Tests

- `local/kbacompany/tests/communication_write_gate_test.php` (added to the STANDARD allowlist): re-entrancy and
  release; failed send writes nothing; a revoked author cannot send or edit while the other company still works;
  revoked, suspended and deleted recipients get no notification; a suspended author cannot send; a revoked user cannot be added;
  a removed member is not notified; literal inline mentions.
- `tools/tests/test_communication_write_gate_protocol.py`: static lock-protocol guard. Not a concurrency proof.
- Not covered here: parallel processes (send racing revoke, N21/N28), MariaDB/Percona runs. Planned for PR-3.
