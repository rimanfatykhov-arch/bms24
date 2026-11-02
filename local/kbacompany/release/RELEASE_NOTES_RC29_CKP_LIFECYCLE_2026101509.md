# RC29 build2026101509 — CKP lifecycle

- Schema migration adds status (active/archived) to local_kbacompany_product with active default.
- Company-scoped POST+sesskey archive/restore in CKP DetailView; archived CKPs read-only, but visible in registry/detail.
- Existing linked metrics and historical plan/fact preserved; no cascade. New metric references to archived CKPs rejected by service.
- PHP lint and ZIP integrity checked; live Moodle, roles and cross-company tests outstanding.
