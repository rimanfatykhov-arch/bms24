# Current navigation — 0.9.0.1

The current sidebar uses workspace / architecture / people / management. Management
subnavigation is a five-section semantic link list with only the active section peers.
See NAVIGATION_0.9.0.1.md. Historical contracts below remain implementation history,
not the current menu layout. KBA Theme still receives the existing groups/items shape;
no business-route catalogue was added to the theme.

# BMS UI integration — workspace model v0.4.6.4

BMS Core now exposes a simplified App Shell navigation model with three sidebar groups: `work`, `management`, and `more`. Detailed architecture destinations are marked as tree-only and are rendered in the dedicated Business Architecture Center, not duplicated in the daily workspace sidebar.

Workspace dashboard semantics:
- personal counters: inbox, tasks, review/control, learning;
- My Work task projection;
- Needs Attention signals;
- compact Business Architecture summary and route;
- company context;
- quick actions: create task and open messaging.

No business menu labels, routes, permissions or counters are hardcoded in KBA Theme.

# BMS UI integration — local_kbacompany 0.4.6.1

## Boundary

- BMS Core owns navigation, routes, permissions, counters, object data and work-source semantics.
- KBA Theme owns App Shell and visual components only.
- KBA Theme is optional; standard Moodle fallback remains mandatory.

## Workspace controller

`bms_workspace.php` builds its model through `bms_ui_service`. The service is renderer-agnostic and returns semantic icon descriptors. The controller selects `bms`/`standard` pagelayout before the first `$OUTPUT` method, then converts descriptors to rendered icons.

## Work model

Workspace daily-work counters/cards use `communication_center_service`, which delegates task aggregation to `task_provider_service`.

Current task providers:

- `bms` — canonical BMS Task Center;
- `legacy` — read-only KBACorporate workflow tasks;
- `kbaelement` — explicitly bound learning activities projected from Moodle completion.

Communication Center is a BMS-owned route in the Work navigation group. Theme templates must not hardcode its existence or permission rules.

## Theme capability check

Enhanced shell requires active `theme_kba` build >= `2026091325` (KBA Theme 2.3.25). Otherwise BMS uses `standard` Moodle layout and `local_kbacompany/bms_workspace_fallback`.

## 0.4.6.6 — Unified Company Workspace Entry

- `company.php` is now a validated compatibility route into `bms_workspace.php`; the duplicate classic company grid is retired.
- company chooser cards and search open the BMS Workspace directly.
- legacy "back to company" links return to the BMS Workspace.
- technical company metadata formerly shown only on the classic page is preserved in a collapsible Company card inside the Workspace.
- the Workspace no longer advertises a "Classic overview" action.
- no database schema change.



## 0.4.6.7 — Page Shell Adapter

The App Shell is no longer limited to Workspace and Business Architecture. BMS Core now provides `bms_page_shell_service` for ordinary business pages. The adapter does not move forms, validation, writes or permissions into the theme. It only wraps the already-generated business page body in `theme_kba/bms/app_shell` when the supported KBA Theme is active.

## 0.4.6.8 — Business Architecture UX model

The architecture page now supplies two explicit BMS-owned presentation models: `architecture_flow` and a grouped `tree`. KBA Theme must render the supplied order and must not infer or hardcode architecture hierarchy from route names.


## 0.4.6.9 — Guided architecture context

BMS Core now supplies stage progress/state, evidence facts, selected stage/focus and recommended next stage. KBA Theme remains presentation-only. No theme-side progress calculation or route hardcoding is permitted.

## 0.4.6.11 — OFS visual content

`ofs_designer.php` uses the normal BMS App Shell but owns its domain-specific organization-poster content inside `local_kbacompany`. KBA Theme remains responsible for the outer shell and generic components only. The theme must not hardcode OFS tiers, products or design actions.

The visual designer is intentionally wide and horizontally scrollable on smaller screens. Print CSS hides editor controls but does not replace the future vector export engine.


## OFS exports — 0.4.6.12

The OFS Visual Designer now supplies BMS-owned download links for SVG and Draw.io plus a PDF paper-size form. KBA Theme may style these generic controls but must not generate export files or decide object access. Export endpoints remain in `local_kbacompany`.

### OFS Template Library
`/local/kbacompany/ofs_templates.php?uuid=<companyuuid>` is a BMS-owned catalogue page. KBA Theme may style generic cards/forms only. Template names, descriptions, preview data, permissions and create actions are provided by BMS Core and must not be hardcoded in the theme.


## Administrative Cycle route — 0.4.8

BMS Core now exposes `admincycle` → `administrative_cycle.php` as the customer-facing management route. The old `adminscale` route remains a hidden compatibility endpoint. KBA Theme must continue to render the route model supplied by BMS Core and must not hardcode either route or the management-stage contents.


## Recurring Tasks routes — 0.4.9

Task Center exposes two BMS-owned routes under the existing `tasks` App Shell context:

- `/local/kbacompany/recurring_tasks.php?uuid=...`
- `/local/kbacompany/recurring_task_library.php?uuid=...`

The theme must not hardcode recurrence semantics or library entries. BMS Core owns statuses, cadence labels, permissions, routes, source traceability and generated task data.


## Financial Model route — 0.6.0

`/local/kbacompany/financial_model.php?uuid=...` is a BMS-owned management/architecture route. `bms_ui_service` supplies its route and Business Architecture placement. KBA Theme may render generic shell/table/form components only; scenario types, responsibility-center semantics, permissions, validation and persistence remain in `local_kbacompany` and must not be hardcoded in the theme.


## Financial Statements route — 0.6.1

`/local/kbacompany/financial_statements.php?uuid=...` is a BMS-owned financial-management route opened from the Financial Model workspace. BMS Core owns statement types, Scenario/Centre revision pins, formulas, lifecycle, validation, ACL and persistence. KBA Theme may style the generic App Shell, tables, forms, alerts and buttons only; it must not calculate BDR/BDDS/balance values, infer financial permissions or implement financial business rules.


## Budgeting route — 0.6.2

`/local/kbacompany/budgeting.php?uuid=...` is a BMS-owned financial-management route opened from the Financial Model and Financial Statements workspaces. BMS Core owns budget targets, Scenario/Centre revision pins, line limits, approval lifecycle, plan/fact snapshot rules, ACL and persistence. KBA Theme may style only generic App Shell, forms, tables, buttons and alerts; it must not calculate limits, infer approvers, store facts or duplicate financial business logic.


## v0.6.3 Resource Gate UI

`resource_gate.php` is a BMS business route rendered inside the existing App Shell. KBA Theme receives only normal presentation output. The route, lifecycle, permissions, target/reference validation and readiness rules remain owned by `local_kbacompany`.
