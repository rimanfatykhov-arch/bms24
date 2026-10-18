# TEST REPORT — v0.30.2

Static release gate completed in build environment:
- PHP syntax check: PASS.
- ZIP single root `kbacorporate/`: PASS.
- ZIP integrity: PASS.
- No DB schema change: PASS.
- Build increased to 2026090602: PASS.

Runtime Moodle verification required by operator:
1. Install upgrade.
2. Open Framework Manager.
3. Confirm `Импорт инструментов JSON` button.
4. Upload a current `kba-bms-tools` export and run preview.
5. Confirm Apply is available only with zero preview errors.
