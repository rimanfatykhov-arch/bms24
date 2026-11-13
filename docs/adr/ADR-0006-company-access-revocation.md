# ADR-0006 — Explicit company access revocation across BMS and Corporate

Status: policy approved by owner 2026-11-11; implementation candidate, acceptance HOLD.
Base: PR #69, ac7148f9c7a8f6d524c8a67896eec9ca2a31326f. Related: Issue #44, S12.

## Evidence and decision

On an isolated production-backup restore, closing a synthetic user's BMS membership
left their old browser session able to read BMS company tasks and their Corporate
development plan. Corporate delete_member denied access but deleted the plan.
Neither result meets the approved policy: revoke both company scopes and retain history.

An explicit deny is authoritative for a company/user, before legacy membership,
self/owner/curator access, global capability or site-admin grants. It applies on each
request/service boundary, including existing sessions. Other companies remain accessible.
Normal membership inactivity is not sufficient evidence of this explicit decision.

## Implementation

Add local_kbacompany_revoke with company UUID, subject, administrative actor, timestamps
and explicit reopen audit. New installations and upgrades create the same empty table;
there is no historical backfill. Existing migration savepoints remain unchanged.
Revoke and ensure_membership use the same company/user lock. Revoke closes active BMS
membership rows and writes the existing outbox event; it never calls Corporate delete_member.
Corporate member, plan, task, assessment and file history remain intact.

Corporate adapters resolve both immutable identity-registry mappings and canonical
legacycompanyid mappings. Query predicates exclude revoked companies before fetching
company-scoped data. Direct plan writes also check the acting user before mutation.
Sync/ensure_membership cannot clear a revoke. An authorised administrator must explicitly
reopen access; this closes the denial record without deleting it. Existing positive
Corporate grants then apply; creation of a new BMS membership is a separate explicit step.
Site-admin control-plane reopen is permitted; ordinary revoked company reads/writes are not.

Privacy export includes revocation records. Erasure minimises audit details but retains
an active company/user deny while the account can log in, so erasure cannot grant access.
Closed records and records for deleted accounts may be erased by the privacy operation.
This is separate from ordinary membership closure, which retains history.

## Version and deployment boundary

BMS2026111100 and retained Corporate2613-derived2026111100 are a paired candidate.
Both are higher than PR69's BMS2026111002 / repaired Corporate2026111004.
The retained2613 source manifest and exact patch define the Corporate artifact; the
canonical Corporate tree is a separate diagnostic line, not a substitute.
No Moodle Core, production, staging, historical UUIDs or published data are changed.

## Acceptance and rollback

Required: fresh/upgrade schema parity, full BMS/Corporate suites on MariaDB10.11 and
Percona8.0, old/new-session revocation, two-company positive controls, denied mutations,
file/API/export boundaries, sync refusal, authorised reopen and retained history hashes.
Until exact results are recorded this candidate and RC30 remain HOLD.

Rollback of an isolated test uses its verified pre-upgrade database/code/moodledata
snapshot. Do not merely downgrade code against the new database: older code ignores
active denials. Production rollback requires restoring the consistent pre-upgrade set,
keeping access closed until checks complete. No production execution is authorised here.
