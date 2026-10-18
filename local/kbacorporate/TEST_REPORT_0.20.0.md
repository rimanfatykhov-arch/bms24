# KBA Corporate Dashboard 0.20.0 — Test Report

Build: `2026090237`

## Automated regression scope

- Contract version, declared ownership and privacy rules.
- Idempotent company and employee upserts.
- Provider-separated external mappings.
- Additive access-role merge.
- ERP-owned business-role assignment activation/deactivation.
- Protection of pre-existing manual role assignments.
- Cursor pagination and absence of employee name/email fields.
- Existing Public API methods remain listed and API release is 1.16.

## Local gate

- XMLDB, plugin version and upgrade savepoint are consistent.
- Moodle service/function declarations and capability references are structurally checked.
- RU/EN keys are unique and in parity.
- PHP/CSS structural and ZIP integrity checks pass before delivery.

## Target Moodle gate required

- Run `php -l` for all PHP files and the plugin PHPUnit suite.
- Complete an upgrade from the currently installed version without uninstalling the plugin.
- Confirm the External Service is installed disabled and restricted.
- Test contract, company → employee → role assignment push order with a dedicated service account.
- Repeat every push and confirm no duplicate company, membership, mapping or assignment.
- Confirm a manual role assignment cannot be removed by an ERP deactivation event.
- Pull more than one page and confirm cursor continuity and company isolation.
- Confirm no name, email, evidence text or credentials appear in the response.
