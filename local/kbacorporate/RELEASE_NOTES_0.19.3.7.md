# KBA Corporate Dashboard 0.19.3.7

Build: `2026090235`

- Added a common `← Back to dashboard` link to standalone company-scoped pages.
- The link always retains the selected `companyid`.
- The link is rendered only when the viewer has corporate-dashboard access.
- Existing local navigation, forms, filters, actions, exports and APIs are unchanged.
- Global administration and diagnostic pages remain outside company navigation because they do not have one selected company.

This release has no database schema changes and upgrades directly from 0.19.3.6.
