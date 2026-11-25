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
  - mini chart geometry (empty series, period bounds, single fact) is computed on the server; since #192 the mini chart itself is drawn by ECharts.
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
  - writes `data-*` view state for assistive QA (since #192 on the chart container, see below).
- Strings: 39 `infocenter2_*` keys in RU and EN, appended as a marker block. This is registered in `tools/check_pr53_pr56_integration.py` with the evidence `information-centre-divisions-source-preservation.json`.

## Apache ECharts (#192)

Both the mini charts and the drill-down chart are drawn with Apache ECharts. The PHP data model, access checks, company isolation and RU/EN strings are unchanged; no string was added.

- One local AMD module for both: `local/kbacompany/amd/src/echarts-lazy.js` (byte-identical copy in `amd/build/echarts-lazy.min.js`, the repository convention). The `-lazy` suffix keeps it out of Moodle's combined `core/first.js`; it is requested once, only by the Information Centre page, from the Moodle server (no CDN, no third-party request). `infocenter_chart` depends on it.
- Version: Apache ECharts **6.1.0** with ZRender 6.1.0 and tslib 2.3.0, built with esbuild 0.28.2 (all exact pins with `package-lock.json`).
- Contents: line chart, grid, tooltip (with its axis pointer), inside data zoom, mark line, mark area, SVG renderer. Size 553 084 bytes (about 189 KB gzip).
- Build: `cd tools/echarts && npm ci && npm run build` writes the module pair and the licence files; `npm run check` rebuilds in memory and fails if the committed files differ. The build refuses to run when the installed versions differ from the pins.
- Licence: Apache License 2.0 (ECharts, with its NOTICE and the d3.js BSD 3-Clause notice), BSD 3-Clause (ZRender), 0BSD (tslib). Texts in `local/kbacompany/thirdparty/echarts/`; registered in `local/kbacompany/thirdpartylibs.xml`.
- Mini charts:
  - each column tile keeps its height (main 140 px, column 72 px); real dots, the line is broken at missing periods (`connectNulls: false`);
  - period bounds and the single-value note stay server-rendered; an empty series shows "No data" and no chart; a zero is a value;
  - no zoom and no pointer handling inside the tile (`pointer-events: none`, silent series), so a click opens the large chart;
  - initialised when the tile comes within 100 px of the viewport (IntersectionObserver, immediate fallback) and resized with the tile (ResizeObserver, window resize fallback).
- Large chart:
  - separate fact, plan and dashed trend series; gaps as mark areas; selected date as a mark line; a real zero inside the range as a value line;
  - windows 13/26/52; inside data zoom for the mouse wheel, mouse drag and touch drag/pinch; buttons and the existing keys; a click selects the nearest date;
  - on pages without touch events (for example desktop Firefox on a touch screen), touch and pen pointers pan and pinch through a small fallback in `infocenter_chart`;
  - the instance is created when the dialog opens, resized with the dialog and disposed when it closes; an empty series opens the dialog with "No data".
- View state for QA: `data-engine`, `data-start`, `data-end`, `data-span`, `data-selected`, `data-lo`, `data-hi`, `data-empty`, `data-gaps`, `data-factlines`, `data-planlines`, `data-trendlines`, `data-dots` and `data-window` on the chart container; `data-engine`, `data-dots`, `data-lines`, `data-gaps` and `data-grid` on each mini chart.
- BROWSER acceptance: the same 7 checks, stronger.
  - The producer reads the ECharts option back from each instance; the contract requires it to match the published view state and the fixture.
  - It also requires an SVG drawing sized to its box, lazy initialisation on the phone and exactly one module request from Moodle.
  - Further checks: a mouse click selection, a pinch through real touch input (375) or touch pointers (1440), resize of the mini and the large chart, disposal on every close, and the main chart re-opened after the other tiles.

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
- Not covered by CI: real TEST data, real touch hardware (pinch is CDP touch input at 375 and synthetic touch pointers at 1440), browsers other than Chromium, and screen-reader runs.

## Deployment note

The plugin version is not changed (`2026112301`) because there is no schema step. This follows the ADR-0011 convention. When the package is installed on TEST, purge the Moodle caches so that the new strings and the AMD modules (including `echarts-lazy`) are served.
