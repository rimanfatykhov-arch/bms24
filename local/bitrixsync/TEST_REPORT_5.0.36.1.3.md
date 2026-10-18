# TEST REPORT — v5.0.36.1.3

Static:
- PHP syntax checked for complete plugin.
- ZIP integrity/root checked.
- version/install/upgrade checked.

Production:
1. Open Catalog Moodle -> Bitrix24.
2. Click `Диагностика привязки товаров`.
3. Review Expected Section and Live Section.
4. If product is absent from category, expected diagnostic is `section_mismatch` or `bitrix_section_empty`.
5. Copy Full Catalog Binding Debug JSON for analysis.
