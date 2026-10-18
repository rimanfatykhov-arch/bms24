# Test report — 0.24.2

## Local checks

- XMLDB install schema parses successfully.
- Russian and English language packs have matching, non-duplicate keys.
- Version, install schema, upgrade savepoint and release manifest use build `2026090262`.
- New program fields exist in both clean-install and upgrade definitions.
- Full ZIP uses the required `kbacorporate/` root and passes archive integrity validation.

## Server acceptance

- Change only contact details and verify that no status notification is sent.
- Change commercial status, add a response and verify the customer notification.
- Change delivery status and verify the updated status/date in the customer card.
- Verify that a mail delivery failure does not undo the saved status.
- Run `php -l`, Moodle upgrade, cache purge and the project test suite on the target server.
