# RELEASE MANIFEST — KBA Connect v5.0.33 RC1

- Component: `local_bitrixsync`
- Build: `2026083300`
- Release: `KBA Connect v5.0.33 RC1 — Corporate Dashboard Pairing`
- Base: v5.0.32 RC2 FULL
- Target Moodle: 4.1.22
- Target PHP: 7.4.33
- Corporate Dashboard preferred contract: v0.14.4 / Public API 1.11

## New code

- `classes/services/corporate_pairing_service.php`
- `classes/services/corporate_connection_service.php`
- `classes/repositories/corporate_pairing_repository.php`
- `classes/repositories/corporate_connection_repository.php`
- `classes/repositories/corporate_pair_attempt_repository.php`
- `corporate/pair/index.php`
- `corporate/connection/status/index.php`

## Modified

- `classes/security/corporate_endpoint_auth.php`
- `classes/logging/logger.php`
- `corporate_rules.php`
- `corporate_schema_diagnostics.php`
- `db/install.xml`
- `db/upgrade.php`
- `version.php`
