# Test report — 0.24.0

## Automated local checks

- XMLDB install schema parses successfully.
- Russian and English language packs have matching, non-duplicate keys.
- Plugin version, install schema and release manifest are consistent.
- PHP files pass structural delimiter and conflict-marker checks.
- Full installation ZIP has the required `kbacorporate/` root and passes archive integrity validation.

## Required server acceptance checks

- Run Moodle upgrade and purge caches.
- Select each active package and verify the request confirmation page.
- Verify required contact name/email validation and optional phone/comment storage.
- Verify the saved request card in the customer workspace.
- Verify contact editing and CSV export as an administrator.
- If configured, verify Bitrix24 deal creation and request details in its description.
- Run `php -l` and the project PHPUnit/acceptance suite on the target Moodle PHP stack.
