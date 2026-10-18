# TEST REPORT — v0.31.2.4

## Static release gate
- PHP syntax: PASS (all PHP files in plugin tree).
- ZIP root: PASS (`kbacorporate/` only).
- ZIP integrity: PASS.
- version.php numeric build increased to 2026090612: PASS.
- install.xml unchanged: PASS.
- DB migration: not applicable.

## Root-cause regression scenarios
1. Edit company role with `returncompanyid > 0`: expected company access check and normal save.
2. Edit global role template from company context: expected role `companyid=0`, return context preserved separately, no access check against company 0.
3. Submit global role form: expected hidden role `companyid=0` not to overwrite return company context.
4. Open global role edit without return company as siteadmin: expected page to render without database lookup for company id 0.

## Runtime status
- Static checks passed.
- Moodle runtime verification pending installation in pilot environment.
