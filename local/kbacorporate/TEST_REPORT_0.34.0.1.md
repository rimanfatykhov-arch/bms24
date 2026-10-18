# Test Report v0.34.0.1
## Доступ клиента к внедрению BMS

## Static release checks

- PHP syntax: **252/252 files passed**.
- `db/install.xml`: **valid XML**; schema unchanged.
- ZIP root and integrity: verified during final packaging.
- RU/EN language-key parity: **2969/2969**, no duplicates or missing keys.

## Implemented regression coverage

`tests/company_implementation_access_test.php` verifies:

1. A generated corporate manager receives `managecompanyimplementation`.
2. The manager can access implementation for their own company.
3. The same manager cannot access another company.
4. The manager does not receive global `manageimplementationaudits`.
5. Capability without full company membership does not grant access.
6. The scoped require helper returns the correct company for an authorised manager.

## Manual pilot scenarios required

- Existing corporate manager after upgrade sees the BMS implementation card.
- Newly created corporate manager sees the card without manual role editing.
- Audit creation, assessment, confirmation, request, plan and tasks work for own company.
- Task discussion/checklist/evidence review works for authorised company manager.
- Economic indicator registry is available for own company.
- Framework Manager, global tool library and JSON administration remain denied.
- Direct `companyid` substitution is denied.
- Curator and site-administrator workflows remain unchanged.
- Global Kanban remains limited to curator/BMS administrator.

## Runtime status

Full Moodle PHPUnit and browser runtime tests require the configured pilot Moodle environment and are not claimed as executed locally.
