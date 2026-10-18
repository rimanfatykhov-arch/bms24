# KBA Corporate Dashboard 0.19.3.8

Build: `2026090236`

- Fixed return navigation on `Мои компетенции`.
- Managers with access to the corporate dashboard see `← Вернуться в кабинет`; the selected `companyid` is retained.
- Employees without that access see `← Вернуться в личный кабинет` and return to Moodle `/my/`.
- The same fallback covers an unresolved or not-yet-selected company context.

This release has no database schema changes and upgrades directly from 0.19.3.7.
