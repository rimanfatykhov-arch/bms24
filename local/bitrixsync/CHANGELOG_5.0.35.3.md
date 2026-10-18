# KBA Connect v5.0.35.3 — Setup Center Corporate Status Fix

- Setup Center Corporate readiness now uses active Pairing / Connection API records as the primary source of truth.
- Legacy `corporate_enable + corporate_api_url` remains fallback-only.
- Connected installations now display `Готово` instead of `Нужно настроить`.
- Corporate card displays environment, API version, active connection count and component when available.
- Button changes to `Открыть Corporate` when the connection is ready.
