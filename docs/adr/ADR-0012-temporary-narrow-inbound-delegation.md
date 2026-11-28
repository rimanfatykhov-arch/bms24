# ADR-0012 — Temporary narrow inbound delegation for the fake MAX slice

Status: owner decisions D1–D4 approved 2026-11-24 (all variant «А»); implementation candidate, acceptance HOLD.
Scope: RC33 fake-provider MAX → corporate feed only (contract `docs/architecture/RC33_MAX_FEED_INBOUND_CONTRACT.md`,
PR #88 / issue #75). Stack: PR-1 #178 (communication write gate) → this PR-2 (server side) → PR-3 (fake adapter).

## Context

The contract requires an external transport account to place a message into a BMS discussion on behalf of a human
employee. A general model for agent and integration principals (BMOS-A0, #76/#84) is **not accepted**. The owner
chose a fake provider only (D1), one group to one discussion (D2), text only (D3) and off by default (D4).

## Decision

A temporary delegation, limited to this slice:

- One `service_integration` principal per company and provider (`local_kbacompany_inbconn`). It is a dedicated live
  Moodle account: not a site administrator, not the approving manager, and serving no other connection.
- The new capability `local/kbacompany:importinbound` (CONTEXT_SYSTEM, RISK_SPAM | RISK_PERSONAL) is granted to no
  archetype. It works only through the separate restricted service `local_kbacompany_public_inbound_v1`, which is
  disabled and restricted. The import switch `local_kbacompany/inbound_max_enabled` is off by default. The read-only
  `local_kbacompany_public_v1` is unchanged.
- The principal is the transport, never the author. The canonical `communication_service::send_message` runs as the
  mapped human. That human is confirmed through a sender binding with synthetic evidence (`synthetic:`) and consent
  for one group binding and its policy version. The audit records both actors: `principaluserid` and `actoruserid`.
- Only a company organisation manager or a site administrator writes the registry. The principal has no registry
  endpoint, so it can never approve a binding or consent for itself.
- The delegation does not transfer any other right: no reading, no other threads, no files, no mentions, no edits
  or deletion, no RBAC, approvals or calendar. Imported text keeps `@Name` literal.
- Fixed audience. While an import binding of a discussion is not revoked, `add_participant` refuses new readers.
- Serialization. Intake takes `inbsrc:{source key}`, then the company `communication_write_gate`, and holds the gate
  through its commit. Registry writers, ADR-0006 membership revocation and participant writers take the same gate.
  A revocation therefore commits either before an import or after it, never in between.
- Every decision that is not a publication is durable per source key and stores no body. A later retry returns the
  same answer. Control events (edit/remove) only hold or mark for review; they never edit or remove a message.

## Not decided here (remain HOLD)

- Live MAX: a bot, tokens, the network and files. This waits for D5–D8, and an owner token is never requested.
- A general integration or agent principal model (BMOS-A0). This ADR must not be cited as precedent for it.
- Object-ACL writers, `meeting_service` transactions, registry merge, privacy erasure and core user suspension do
  not take the gate (PR-1 residual risk). The import still re-checks the author's user, company and participant
  state under the gate, and the N28 object-ACL race is an open owner question.

## Removal

The slice is removed or replaced by an accepted principal model. To remove it, disable the service, clear the
switch and revoke the connections. Receipts and holds stay so that no old source is imported twice.
