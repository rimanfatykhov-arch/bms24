# Upgrade to local_kbacompany 0.9.2

1. Back up Moodle code, DB and moodledata.
2. Validate first on a test copy.
3. Install the full `INSTALL_ME_local_kbacompany_v0.9.2_build2026092604.zip` over the existing plugin.
4. Complete the standard Moodle upgrade page even though this release adds no schema migration.
5. Purge caches.
6. Open **Settings → Integrations** and verify the new Bitrix24 Provider card.
7. Confirm that existing KBA Connect diagnostics and employee synchronization remain unchanged.
8. If enabling the new external service, restrict it to a dedicated service user and verify Company-scoped access.

Do not remove or replace KBA Connect. 0.9.2 extends its BMS-side contract; it does not supersede the transport plugin.
