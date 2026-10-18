# KBA Corporate Dashboard 0.21.0

Build: `2026090239`  
Public API: `1.16` (unchanged)

## Result

The first BMS implementation-audit contour now runs directly in Moodle while the future Odoo environment is not yet deployed.

## Included

- BMS tool and criterion management per company.
- Four audit types: baseline, progress, final and monitoring.
- Criterion maturity levels from 0 to 5 and a separate not-assessed state.
- Evidence upload through Moodle File API, optional external evidence link and verification status.
- Weighted BMS Index and Evidence Coverage.
- Confirmation gate for required criteria and an immutable confirmed result.
- Safe company deletion ordering for all new records and evidence files.

## Boundary

Employee competency audits remain a separate process. Course completion or competency evidence never confirms that a management tool has been implemented.

