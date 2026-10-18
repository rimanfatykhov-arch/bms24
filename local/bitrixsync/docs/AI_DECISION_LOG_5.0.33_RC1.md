# AI DECISION LOG — KBA Connect v5.0.33 RC1

## Decision: Pairing replaces manual permanent-token exchange

**Context:** Corporate Dashboard v0.14.4 exposes Public API 1.11 and expects KBA Connect pairing endpoints.

**Decision:** use a one-time short-lived code to establish a revocable long-lived integration connection. The long-lived token is returned once to the Dashboard and stored in KBA Connect only as a hash.

**Reason:** reduces manual secret handling, enables revocation, last-seen monitoring and future multi-environment clients.

## Decision: Connection data belongs to KBA Connect

KBA Connect owns Bitrix24 credentials and integration connection state. No Bitrix24 credential is moved into `local_kbacorporate`.

## Decision: Multiple connections are represented in the database

Connection records include component, environment, client version, API version, token fingerprint, created time, last-seen time and revocation state. Current UI primarily manages the latest active connection while the schema supports Production/Test/Development clients.

## Decision: Core enrolment is independent

Pairing/Corporate failures are non-fatal for the primary Bitrix24 → Moodle user/course/group path.

## Decision: Legacy token fallback is transitional

The static `corporate_integration_token` remains accepted for one release to prevent interruption during migration. New connections should use pairing.
