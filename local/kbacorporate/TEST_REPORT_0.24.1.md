# Test report — 0.24.1

## Local checks

- XMLDB install schema parses successfully.
- Russian and English language packs have matching, non-duplicate keys.
- Message-provider strings exist in both languages.
- Version, install schema, upgrade savepoint and release manifest use build `2026090261`.
- Full ZIP uses the required `kbacorporate/` root and passes archive integrity validation.

## Server acceptance

- Submit a new package request and verify it remains saved.
- Verify the requester receives a popup notification and, when enabled, an email.
- Verify an authorised implementation administrator receives a separate alert.
- Open both notification links and confirm the correct active client.
- Simulate an unavailable email transport and verify request creation still succeeds.
- Run `php -l`, Moodle upgrade, cache purge and the project test suite on the target server.
