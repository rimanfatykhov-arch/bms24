# BMS24 RC29 build2026101735 — Brand rendering hotfix

Fixed visible `[[bms24_brand_subtitle]]` caused by Moodle stale language cache. Reuses the long-established localized `pluginname` rather than a newly added key. Corrected CSS specificity so the expanded sidebar reliably displays a large, left-aligned BMS24 wordmark above a single-line subtitle, while compact state still hides both. No DDL, rights, or business logic changes. Live Moodle verification pending.
