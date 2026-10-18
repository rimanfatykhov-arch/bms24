# TEST REPORT — v0.30.3

Static release gate performed in build environment:
- PHP syntax check: required for all PHP files.
- ZIP root: exactly `kbacorporate/`.
- ZIP integrity validation.
- Numeric build raised to 2026090605.
- No DB schema migration.
- Domain-node cleanup is explicit, not automatic.

Production functional checks required after install:
1. Framework Manager shows coverage card and Integrity button.
2. Integrity page reports current BMS-D00 test mapping.
3. Cleanup removes only BMS-Dxx mappings and marks tool draft.
4. Coverage becomes 137/137 and domain mappings become 0.
5. JSON import with competency_code BMS-Dxx is blocked.
