# KBA Connect v5.0.33 RC1 — Corporate Dashboard Pairing

## Purpose

Replace manual permanent Bearer-token copying with a one-time pairing workflow between KBA Connect and KBA Corporate Dashboard v0.14.4 / Public API 1.11.

## Pairing flow

1. Administrator opens **KBA Connect → Corporate**.
2. Clicks **Создать код подключения**.
3. KBA Connect displays a one-time `KBA-XXXX-XXXX` code valid for 15 minutes.
4. Corporate Dashboard sends the code to `POST /local/bitrixsync/corporate/pair` with optional client metadata.
5. KBA Connect validates TTL, single-use state, client compatibility and IP rate limits.
6. KBA Connect returns a long random integration token once and stores only its SHA-256 hash.
7. Corporate Dashboard uses `Authorization: Bearer <integration-token>` for connection and schema endpoints.

## Endpoints

- `POST /local/bitrixsync/corporate/pair`
- `GET /local/bitrixsync/corporate/connection/status`
- `GET /local/bitrixsync/corporate/schema/status`
- `POST /local/bitrixsync/corporate/schema/provision`

## Security

- Pairing code: cryptographically generated, 15-minute TTL, single-use.
- Pair endpoint: JSON-only, IP attempt limiting, temporary blocking.
- Integration token: 96 hexadecimal characters generated from 48 random bytes.
- KBA Connect stores only token hash and short fingerprint.
- Full pairing codes and integration tokens are never written to logs.
- Existing manually configured Bearer token remains as a one-release compatibility fallback.

## Backward compatibility

Corporate pairing failures never roll back the core Bitrix24 → Moodle user/enrolment/group flow. The existing Corporate Dashboard local bridge remains available for pre-pairing integrations.
