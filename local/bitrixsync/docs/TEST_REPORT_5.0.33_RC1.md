# TEST REPORT — KBA Connect v5.0.33 RC1

## Static release checks

- [x] `version.php` version bumped to `2026083300`.
- [x] `db/install.xml` contains pairing, connection, and rate-limit tables.
- [x] `db/upgrade.php` contains `xmldb_local_bitrixsync_upgrade()` migration to `2026083300`.
- [x] English language directory present.
- [x] Russian language directory present.
- [x] Pair endpoint is POST-only and JSON-only.
- [x] Connection status endpoint is GET-only and Bearer protected.
- [x] Schema endpoints use the upgraded connection-token Bearer validator.
- [x] Token hashes are stored; plaintext token is returned only at pairing time.
- [x] Pairing logger removes token/secret/pairing-code fields.
- [x] Pairing code TTL and single-use state implemented.
- [x] IP attempt limit and temporary blocking implemented.
- [x] Connection revocation implemented.
- [x] Legacy static Bearer token fallback retained for one release.

## Production acceptance tests after installation

- [ ] Generate a pairing code in KBA Connect.
- [ ] Pair Corporate Dashboard v0.14.4 / API 1.11.
- [ ] Reuse same code → `pairing_code_used`.
- [ ] Expired code → `pairing_code_expired`.
- [ ] Invalid repeated codes → rate limiting.
- [ ] `connection/status` → `connected=true`.
- [ ] Bitrix unavailable does not report Dashboard↔Connect as disconnected.
- [ ] `schema/status` works with paired token.
- [ ] `schema/provision` remains idempotent.
- [ ] Revoke connection → old Bearer token rejected.
- [ ] Existing Deal 3761 sync still enrols users without regression.
