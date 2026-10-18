# Test Report — v0.29.6.3

## Static release gate
- ZIP root: `kbacorporate/`
- Moodle component: `local_kbacorporate`
- Build: `2026090307`
- Upgrade source: `2026090306`
- `lang/ru` and `lang/en`: present
- Database schema changes: none
- PHP syntax scan: required before release
- ZIP integrity: required before release

## Functional checks on Moodle
1. Open `/local/kbacorporate/implementation.php?companyid=1`.
2. Confirm there is no standalone `Инструменты BMS` card.
3. Confirm `Методология BMS` contains:
   - Framework Manager;
   - Библиотека инструментов BMS;
   - JSON: компетенции ↔ инструменты.
4. Confirm active highlighting on `framework.php`, `bms_tools.php` and `bms_competency_io.php`.
5. Confirm responsive layout at desktop, tablet and mobile widths.
