# Test Report — v0.29.6.2

## Static release gate
- ZIP root: `kbacorporate/`
- Component: `local_kbacorporate`
- Build: `2026090306`
- `lang/ru` and `lang/en`: present
- `db/upgrade.php`: savepoint added; no schema changes
- PHP syntax: checked
- ZIP integrity: checked

## Server regression checklist
1. Upgrade from build `2026090305` completes in Site administration → Notifications.
2. Open `/local/kbacorporate/implementation.php?companyid=1`.
3. Confirm six BMS groups are visible: Client, Diagnostics, BMS Tools, Framework, Implementation, Analytics.
4. Confirm all visible links open without changing company context where applicable.
5. Open `/local/kbacorporate/bms_competency_io.php?companyid=1` and confirm the JSON item is highlighted.
6. Verify layout at desktop and narrow/mobile widths.
