# BMS24 1.1.0-rc29 build 2026101658

## Battle Plan — manager dashboard drill-down

- Manager summary cards are now actionable links to the relevant filtered canonical Battle Plan view.
- Missing plan opens Plan coverage filtered to missing plans.
- Draft/submitted/active cards open the Battle Plan list filtered by canonical lifecycle status.
- Overdue/needs-decision cards open Management overview filtered by canonical attention state.
- Added a compact list of concrete employees/plans requiring attention, derived only from the existing coverage, overview and readiness read models.
- No database schema, ACL/RBAC, privacy or company-isolation changes.
