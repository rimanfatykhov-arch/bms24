# Information Centre by divisions (#190)

Status: source candidate in a separate PR. Not accepted, not deployed. Production and staging are out of scope.

## What changes

- `local/kbacompany/information_center.php` — read-only page:
  - the main company metric is on top;
  - divisions of the default organizing-board branch are shown as columns in board order (sortorder, code, uuid);
  - each column lists its departments/sections and positions (depth-first) with the metrics bound to them in the indicator registry;
  - metrics without a binding are listed separately;
  - a division without metrics says so.
- `classes/local/information_center_service.php` — the render model:
  - built on `indicator_registry_service::context()`, so company membership and company-UUID scope come from the registry, and `series_for_metric()` reads only company-scoped rows;
  - missing periods become explicit gaps (daily/weekly/monthly/quarterly/annual steps), never zeroes;
  - mini chart geometry is computed on the server.
- Trend:
  - comes from `indicator_trend_service::analyze()` over the mini window (13 real periods);
  - it is shown as text plus a symbol (↑ ↓ → ≈ ·), not as colour only;
  - "better/worse" follows the metric direction.
- `amd/src/infocenter_chart.js` (the build is an exact copy) — the drill-down chart in a modal dialog:
  - shows axes and the unit;
  - shows the exact fact and plan on the selected date;
  - draws gaps as bands, with the line broken at each gap;
  - draws the plan line when a plan exists, and a dashed least-squares trend over the visible facts (3 or more);
  - offers windows of 13, 26 and 52 periods, plus zoom and pan with buttons, keyboard, mouse wheel and drag, and two-pointer pinch;
  - can be reset;
  - fits the value axis to the visible range, including a flat series (padded) and negative values (real zero emphasised);
  - writes `data-*` view state onto the SVG for assistive QA.
- Strings: 39 `infocenter2_*` keys in RU and EN, appended as a marker block. This is registered in `tools/check_pr53_pr56_integration.py` with the evidence `information-centre-divisions-source-preservation.json`.

## Impact

- DB: none (no schema, no upgrade step, no writes).
- ACL: existing `local/kbacompany:viewowncompany` and company membership only; no new capability.
- Privacy: no new personal data; only metric values already visible in the registry.
- Integrations: none.

## Acceptance evidence

- PHPUnit: `local/kbacompany/tests/information_center_service_test.php` covers:
  - board order and bindings;
  - company isolation (including another company's binding that points at this company's node);
  - access by a non-member;
  - gaps, a real zero, a single point and an empty series;
  - period steps, `trend_view`, and flat/negative geometry.
- BROWSER tier: `tools/ci/browser/infocenter.py` with the fixture `ci_infocenter.php`, using synthetic data that is removed afterwards.
  - Cases: owned 1440, owned 375 and legacy 375.
  - The verdicts are `contract.infocenter_checks` (7 required checks).
  - Screenshots: `infocenter-<case>-overview.png`, `infocenter-<case>-chart.png` and `infocenter-<case>-chart-negative.png`.
- Not covered by CI: real TEST data, real touch hardware (pinch is synthetic two-pointer events), and screen-reader runs.

## Deployment note

The plugin version is not changed (`2026112301`) because there is no schema step. This follows the ADR-0011 convention. When the package is installed on TEST, purge the Moodle caches so that the new strings and the AMD module are served.
