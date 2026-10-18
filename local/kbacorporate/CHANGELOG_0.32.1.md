# Changelog — v0.32.1

## Added

- Employee-wide competency assessment history page.
- Company-scoped assessment event ledger.
- Longitudinal first-to-latest level comparison per competency.
- Source, status, period, trend, scope and text filters.
- Filter-preserving CSV export.
- Navigation from employee card, competency profile, gap report and competency history.
- Regression guards for filtering, deduplication, trend calculation, summaries and date parsing.

## Changed

- Exposed a bulk history-map accessor for company-scoped reporting to avoid N+1 history queries.
- Added explicit separation between assessment history and learning/course evidence.

## Database

- No schema changes.
- Upgrade savepoint only: `2026090806`.
