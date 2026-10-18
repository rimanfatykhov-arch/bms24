# Test report — 0.24.0.1

## Local checks

- XMLDB install schema parses successfully.
- Russian and English language packs have matching, non-duplicate keys.
- Version, install schema, upgrade savepoint and release manifest use build `2026090260`.
- Full ZIP uses the required `kbacorporate/` root and passes archive integrity validation.

## Server acceptance

- Open two different clients as an administrator and verify that the banner changes with the selected company.
- Follow links to audit, assessment, plan, task, economic indicators, request and program settings; verify the same client remains visible.
- Verify that **Select another company** returns to the client registry.
- Run `php -l`, Moodle upgrade and cache purge on the target server.
