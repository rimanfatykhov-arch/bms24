# BMS24 Navigation Standard

Status: **CANONICAL**  
Applies to: `local_kbacompany` BMS24 App Shell and product pages  
Introduced: build `2026101699`  
Documented: build `2026101700`

## 1. Purpose

BMS24 navigation must let a user answer three questions without returning to a home page:

1. **Where am I in the product?**
2. **What neighboring screens belong to the same work context?**
3. **How do I return to the object/process I came from?**

Navigation is presentation and routing only. It must never grant permissions, mutate lifecycle state, create records, or replace controller ACL.

## 2. Navigation hierarchy

### L1 — Product sidebar

The left BMS24 sidebar contains only major product contours and their primary entries. It is the stable global navigation layer.

Canonical contours:

- Workspace
- Architecture
- People
- Management
- Settings / administration where applicable

The sidebar must not become a complete sitemap.

### L2 — Contextual quick navigation

The horizontal quick menu inside the content area contains sibling screens that belong to the user's current work context.

Examples:

- Workspace: Workspace → Calendar → Tasks → Recurring Tasks → Battle Plan → Communication Center
- Management / Planning: Weekly Coordination → Management Week → Battle Plan → Management Cycles
- Management / Indicators: Information Center → Indicators → Exceptions
- Architecture / Strategy: Architecture Center → Owner → Philosophy → Market → Business Model → Strategy → Financial Model → Administrative Scale
- Architecture / Operations: Organization → Positions → Processes → Functions → Business Roles → Flows → Products
- People: Overview → Employees → Positions → Coverage → Culture → Function Transfers → Learning → 360°
- Governance / Quality: Quality Center → Documents → Policies & Directives → Deviations

L2 is implemented by reusable navigation services. Pages must not hand-build copies of these menus.

### L3 — Domain/process local navigation

A complex domain may render a more precise local navigation below or instead of the generic L2 menu.

Example: Management has section navigation (`Overview`, `Planning`, `Indicators`, `Decisions`, `Workspaces`) plus the current section's child links.

A domain-specific local menu must include the marker class:

`bms-context-quicknav`

The App Shell uses this marker to avoid rendering a duplicate quick menu.

### L4 — Breadcrumbs

Breadcrumbs describe hierarchy/history and the current object/page. They are not a replacement for L1/L2/L3.

Typical pattern:

`My company / <Company> / <Current page or object>`

## 3. Active state rules

- Exactly one page-level link should use `aria-current="page"` inside one navigation group.
- A parent section may use location semantics when one of its child pages is active.
- Active styling must be derived from the current canonical route, not from label text.
- A user must be able to identify the current context without relying on color alone.

## 4. Context preservation

Navigation may carry only bounded, explicitly supported context.

Allowed examples:

- company `uuid`
- validated management `week`
- explicitly supported stable filter such as `orgnode` only where the target route owns it

Never copy arbitrary query parameters between pages.

Never propagate:

- `sesskey`
- action/mutation parameters
- another object's UUID unless the target route explicitly owns that object
- unvalidated request payloads

## 5. Permission rules

Navigation visibility is not authorization.

- Menus may hide entries using existing permission services for UX clarity.
- Every destination controller remains authoritative for ACL/RBAC and company isolation.
- A navigation helper must not infer or grant a role.
- A hidden link is not a security control.

## 6. Duplication rules

- Do not show the same sibling menu twice.
- `bms_page_shell_service` may inject generic contextual navigation only when rendered page content does not already contain `bms-context-quicknav`.
- Domain-specific navigation wins over generic shell navigation.
- Do not duplicate every sidebar link inside contextual navigation; include only the bounded current work context.

## 7. Cross-contour rule

A page may be globally reachable from one contour while belonging locally to another work context.

Canonical example:

- **Battle Plan** is reachable from Workspace for daily work.
- Its contextual quick navigation is **Management → Planning**, because weekly planning/review is its management context.

Cross-contour placement must be explicit in `bms_workspace_ux_service::contextual_nav()` and documented here when it materially changes user orientation.

## 8. Canonical implementation

Primary implementation points:

- `classes/local/bms_workspace_ux_service.php`
  - `contextual_nav()`
  - `workspace_nav()`
  - `management_nav()` / `management_nav_model()`
  - `architecture_nav()`
  - `architecture_operations_nav()`
  - `people_nav()`
  - `governance_nav()`
- `classes/local/bms_page_shell_service.php`
  - generic non-duplicating contextual navigation injection

Navigation services are presentation-only and must have `storage = none` semantics.

## 9. Responsive behavior

Desktop:

- quick navigation stays close to the page header;
- links may wrap, but must retain order and readable hit targets;
- active state must stay visible.

Mobile/narrow widths:

- horizontal overflow or wrapping must not hide destinations;
- no hover-only discovery;
- links remain keyboard/touch accessible;
- content must not be pushed beyond the viewport by the navigation bar.

## 10. When contextual quick navigation is justified

Add L2/L3 quick navigation when a domain has **two or more sibling screens that users reasonably switch between during one workflow**.

Do not add it to:

- one-off technical pages;
- destructive confirmation screens;
- isolated setup forms with no sibling workflow;
- pages where the only meaningful action is Back/Cancel to the owning object.

## 11. Adding a new BMS24 page

Before release, the implementer must decide:

1. Which L1 contour owns the page?
2. Does it belong to an existing L2/L3 context?
3. What is its stable route key?
4. Which context parameters, if any, are safe to preserve?
5. Does permission-based visibility reuse an existing permission service?
6. Will App Shell injection create a duplicate menu?

If the page creates a new work context, update this standard and the navigation matrix in the same release.

## 12. Acceptance checklist

For every navigation change verify:

- [ ] correct sidebar contour remains active;
- [ ] contextual quick menu appears where justified;
- [ ] no duplicate contextual menu;
- [ ] correct page-level active state and `aria-current`;
- [ ] company UUID is preserved;
- [ ] validated week is preserved only on supported management pages;
- [ ] no action/sesskey leakage in links;
- [ ] target controller still enforces ACL/RBAC/company isolation;
- [ ] keyboard navigation works;
- [ ] mobile/narrow layout remains usable;
- [ ] RU/EN labels exist and no `[[missing_key]]` is rendered.

## 13. Navigation matrix

| Product contour | Work context | Canonical sibling screens |
| --- | --- | --- |
| Workspace | Daily work | Workspace, Calendar, Tasks, Recurring Tasks, Battle Plan, Communication Center |
| Management | Planning | Weekly Coordination, Management Week, Battle Plan, Management Cycles |
| Management | Indicators | Information Center, Indicators, Exceptions |
| Management | Decisions | Management Loop, Review, History, Patterns |
| Management | Workspaces | Owner Workspace, Curator Workspace |
| Architecture | Strategy | Architecture Center, Owner, Philosophy, Market, Business Model, Strategy, Financial Model, Administrative Scale |
| Architecture | Operating model | Organization, Positions, Processes, Functions, Business Roles, Flows, Products |
| People | People system | Overview, Employees, Positions, Coverage, Culture, Function Transfers, Learning, 360° |
| Governance | Quality | Quality Center, Documents, Policies & Directives, Deviations |

---

**Product rule:** Sidebar = major contour. Contextual quick navigation = current work context. Breadcrumbs = hierarchy/history. Domain-specific navigation wins over generic shell injection.
