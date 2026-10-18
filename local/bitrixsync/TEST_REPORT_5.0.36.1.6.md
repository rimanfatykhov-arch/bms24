# TEST REPORT — v5.0.36.1.6

Static checks:
- complete PHP syntax check;
- Moodle ZIP root/integrity;
- version/install/upgrade.

Production acceptance:
1. Open Moodle -> Bitrix24 Catalog.
2. Click `Восстановить связи категорий`.
3. Existing categories should resolve by XML_ID/CODE/exact NAME.
4. `Связано разделов Bitrix24` should count only non-zero section IDs.
5. Run normal catalog Sync.
6. Re-run diagnostics.
7. Expected product rows: Expected Section = Saved Section = Live Section > 0.
8. Bitrix catalog categories should display their Moodle course products.
9. No duplicate sections are created by the repair action.
