# KBA Corporate Dashboard 0.18.4.1

Build: `2026090219`

- Fixes `ddldependencyerror` during upgrade when the plan `status` column is used by indexes.
- Removes the unnecessary existing-column default change.
- A partially attempted v0.18.4 upgrade can be safely resumed.
- New plans still start as drafts because the application explicitly stores that status.
