# TEST REPORT — v5.0.34.5

Static:
- PHP 7.4 syntax checked
- version/install/upgrade checked
- lang/en and lang/ru checked
- ZIP integrity checked

Production:
1. Open Corporate page.
2. Verify Role Schema normalization shows exactly four normalized roles.
3. Verify Unexpected values does not flow into provisioning.
4. Click Create / restore roles.
5. Verify only employee, corporate_manager, department_manager, hr are created.
6. Existing technical enum values are not auto-deleted.
7. Repeat provisioning: no duplicate role values.
