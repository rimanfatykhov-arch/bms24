# TEST REPORT — v5.0.36.1.4

Static:
- Complete PHP syntax check passed.
- ZIP integrity and Moodle root verified.
- version/install/upgrade checked.

Production:
1. Open Catalog Moodle -> Bitrix24.
2. Click `Диагностика привязки товаров`.
3. No Moodle database-read exception is expected.
4. Diagnostic table must show Course ID, Product ID, expected/live iblock and section IDs.
5. Full JSON must include `database` table/field availability.
