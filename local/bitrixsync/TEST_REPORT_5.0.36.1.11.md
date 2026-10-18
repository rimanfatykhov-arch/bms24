# TEST REPORT — v5.0.36.1.11

Static:
- full PHP syntax check;
- ZIP integrity/root verified;
- version/install/upgrade checked.

Production acceptance:
1. Click `Восстановить связи категорий`.
2. Categories 8/9/10 should now insert mapping rows successfully.
3. Expected recovered sections: 99 / 101 / 103 if those remain the Bitrix matches.
4. Run normal Catalog Sync.
5. Re-run binding diagnostics.
6. Expected: Expected Section = Saved Section = Live Section > 0.
