# RC33 PR-2 — MAX fake-provider inbound, server side

Status: implementation candidate, **acceptance HOLD**. The remaining gates are Moodle PHPUnit on MariaDB/Percona,
a real upgrade, the localhost fake adapter, synthetic HTTP and parallel races (PR-3). Executor: Claude.
Base: PR-1 `feature/communication-write-gate` 5aeb26ac1fd158ed45004e76c9d32c4d1002cf6d (#178). Plugin 2026112301 →
**2026112500**, release 1.1.0-rc29. Decision: owner D1–D4 of 24.11.2026 (all «А»), ADR-0012.

## Change

- Schema (additive, empty; upgrade step 2026112500 creates the tables only when they are absent):
  - `inbns`: source namespace per provider and bot-identity hash;
  - `inbconn`: one connection per company and provider; principal, disabled by default;
  - `inbgroup`: one external chat to one group thread, with revision, policy version and effective-from;
  - `inbsender`: verified sender mapping and consent for one binding;
  - `inbreceipt`: unique source key, fingerprint and outcome; **no body**;
  - `inbhold`: edit/remove tombstones per source key;
  - `inbaudit`: dual-actor audit.
- Capability `local/kbacompany:importinbound`, granted to no archetype. The restricted, disabled service
  `local_kbacompany_public_inbound_v1` exposes `local_kbacompany_public_inbound_message` and
  `local_kbacompany_public_inbound_control`. Each takes one JSON `payload` (contract §5).
- `inbound_json`: strict decoder. It rejects duplicate keys, floats, exponents, `-0`, leading zeros, integers over
  15 digits, nesting deeper than 16 and payloads over 256 KiB.
- `max_inbound_service`:
  - the principal resolves its own connection; DTO connection, binding, company and thread values are assertions only;
  - the source key is SHA-256 of length-prefixed (provider, namespace, chat, mid) + `create`;
  - the fingerprint is the canonical semantic DTO, security flags included;
  - lock order is `inbsrc:{key}` → company gate, and every check is re-read under the gate;
  - one transaction holds the receipt, `send_message` as the human author with literal mentions, the receipt update,
    the audit and the neutral outbox `communication.message.created` (no MAX IDs, no text);
  - any failure rolls all of it back and returns `retryable_unavailable`;
  - a replay with the same fingerprint returns the same answer, and with a different one `idempotency_conflict`;
  - the outcome is `review_required` for: stale binding, held source, channel/dialog, bot, attachment, reply or
    forward, age window, before effective, unverified sender, missing consent, unavailable author or thread;
  - the decision is durable per source key; control events only hold or mark for review.
- `max_inbound_registry_service`: the registry writers. Each needs a manager, runs under the company gate in one
  transaction and adds an audit row. Only synthetic evidence is accepted; a namespace is never re-attached.
  A rotation keeps the namespace and the receipts.
- `communication_service::add_participant`: a thread with a live import binding admits no new readers
  (`communication_importaudience`).
- Privacy: metadata, export and erasure. Erasure removes sender mappings and identity in the audit, and revokes a
  connection whose principal is erased. Receipts keep only source identity.
- Lang RU/EN: one append-only block after «Added earlier».
- Source-preservation evidence is in `rc33-pr2-max-inbound-source-preservation.json`.
- Version bump through the full checklist: CI expected version, acceptance constants, schema policies, release
  gate, dev AI gate checkpoint, pr90 upgrade policy and the schema-parity CLI with 14 tables.

## Tests

`local/kbacompany/tests/max_inbound_service_test.php` is in the STANDARD allowlist. It is single-process and covers:

- closed defaults;
- created once, then duplicate, with the dual-actor audit and the neutral outbox;
- N02/N25 strict payloads;
- principal, switch and assertions;
- N03/N04 foreign scope;
- N05–N08 and N32 review reasons;
- N11 conflict;
- N12/N26 namespaces and rotation;
- N13/N29 rollback through `set_test_fault`, including deny-audit failure;
- N14/N16 reply, forward and attachment;
- N15 literal markup and mentions;
- N19/N27 controls;
- N21/N28 revocations committed before intake;
- N22/N24 no body or foreign IDs;
- N30 fixed audience and suspended recipient;
- N31 age window and delayed duplicate;
- registry rules;
- external API capability;
- privacy erasure of the principal.

## Not covered here (PR-3 or later)

- N01: webhook secret, in the adapter.
- N09/N21/N27/N28: parallel processes, 20-way duplicate delivery and revocation during intake.
- N20: HTTP envelopes and retry.
- N23: the outbox consumer.
- N17/N18: files, out of the text-only slice.
- The PR-1 residual risks remain: object-ACL writers, meeting_service, registry merge and core suspension are
  outside the gate. The N28 object-ACL race is an open owner question.
- Live MAX stays HOLD until D5–D8.
