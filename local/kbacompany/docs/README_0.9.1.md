# Install — local_kbacompany 0.9.1 build 2026092600

1. Back up Moodle DB, moodledata and plugin code.
2. Test on a Moodle 4.1.22 copy first.
3. Install/replace the existing local_kbacompany plugin with the release ZIP.
4. Complete the normal Moodle plugin upgrade so `db/services.php` is re-read.
5. Purge caches.
6. Keep `BMS Odoo Adapter API v1` disabled until a dedicated restricted service user and token are configured.
7. In BMS → Settings → Integrations, create/verify an active Odoo company mapping (`res.company`) for the intended environment.
8. Use adapter API readiness before using manifest/mappings.

No Odoo credentials are required by this release because Moodle does not call Odoo yet.
