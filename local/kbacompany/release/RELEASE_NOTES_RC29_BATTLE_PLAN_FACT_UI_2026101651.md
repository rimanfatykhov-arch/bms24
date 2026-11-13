# BMS24 RC29 — Battle Plan fact UI localization fix — build 2026101651

- Removed newly introduced metric-fact language keys from the runtime path that could remain stale in Moodle string cache after upgrade.
- Reused established local_kbacompany language strings for fact state, action and form title.
- Fact form context now renders period and unit without unresolved `[[...]]` placeholders.
- No DB schema, ACL/RBAC, privacy, company-isolation or metric fact persistence changes.
