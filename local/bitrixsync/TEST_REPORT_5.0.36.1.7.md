# TEST REPORT — v5.0.36.1.7

Static:
- all PHP syntax checked;
- install.xml parsed as XML;
- Moodle ZIP integrity/root verified;
- version/upgrade checked.

Production acceptance:
1. Install/upgrade plugin and complete Moodle database upgrade.
2. Catalog page must open without DB-read exception.
3. `local_bitrixsync_catmap` must expose bitrix_section_id, iblockid, status, error and timestamps.
4. Run `Восстановить связи категорий`.
5. Run normal catalog sync.
6. Re-run diagnostics.
7. Expected final binding: Expected Section = Saved Section = Live Section > 0.
