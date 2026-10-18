# System Architecture v0.34.1

## Components

- Page controller: `economic_dashboard.php`.
- CSV controller: `economic_dashboard_export.php`.
- Read model: `classes/local/economic_dashboard.php`.
- Source service: `classes/local/economic_indicators.php`.
- Audit comparison projection: `classes/local/implementation_audit_comparison.php`.
- Presentation: Moodle core charts and `styles.css`.

## Data flow

`company access → filtered economic values → indicator grouping → current/previous/plan/forecast projection → direction-aware deltas → chart arrays → HTML/CSV`.

For audit context:

`audit ID → company validation → directly linked actual values → nearest previous confirmed audit → direction/currency-aware comparison`.

## Limits

- 8 selected indicators per page;
- 18 latest actual points per trend chart;
- no external chart library;
- no write operations in the dashboard service.

## Failure isolation

A missing plan, forecast or previous period produces an explicit unavailable state. Mixed currencies suppress only the affected chart; the rest of the dashboard remains available.
