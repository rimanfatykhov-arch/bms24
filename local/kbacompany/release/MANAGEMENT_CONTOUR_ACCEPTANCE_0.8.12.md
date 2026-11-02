# BMS24 Management Contour Acceptance Gate — 0.8.12

Release 0.8.12 is a stabilization-only gate over the Management contour delivered in 0.8.0–0.8.11.

## Covered by source-level acceptance
- Management Cycle cadence and hierarchy.
- Checkpoint → canonical Meeting link.
- Meeting preparation and weekly protocol read models.
- Battle Plan approval and coordination boundaries.
- A3 management review and standardization routing.
- Explicit next-period rollover without task duplication.
- Owner / Curator workspaces.
- Period/advisory report separation of delivered work from observed business effect.
- Company UUID scope checks for the 0.8.x management services.
- XMLDB table-name limit and install/upgrade parity checks.
- RU/EN language parity and literal get_string coverage.
- PHP syntax and PHP 7.3 compatibility heuristics.

## Explicitly not claimed
- Live upgrade on Moodle 4.1.22.
- PHPUnit / Behat execution.
- Browser/mobile acceptance on a running Moodle instance.
- Production acceptance.

Those checks remain mandatory before production rollout.
