# System Architecture v0.34.0

## Components

- Page controller: `implementation_audit_comparison.php`.
- Domain projection: `classes/local/implementation_audit_comparison.php`.
- Source services: `implementation_audits`, `implementation_evidence`, `economic_indicators`, `implementation_section_progress` access projection.

## Data flow

`confirmed audit pair → immutable criterion-code maps → normalised comparison → section aggregation → task/evidence interval context → linked economic comparison → HTML/CSV`.

## Matching rules

- audit isolation: same company;
- methodology isolation: same tool;
- criterion key: snapshot `criterioncode`;
- time order: audit date, then ID;
- scale comparison: score / scalemax × 100.

## Write model

None. The module is read-only.
