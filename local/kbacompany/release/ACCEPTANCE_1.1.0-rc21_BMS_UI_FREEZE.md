# BMS24 1.1.0-rc21 — BMS UI Freeze / Acceptance

Status: CODE RELEASE CANDIDATE; LIVE MOODLE ACCEPTANCE: NOT RUN.
Baseline: exact SOURCE 1.1.0-rc20 build2026101341; version build2026101342.

## Frozen contracts
- KBA Theme owns presentation/App Shell; local_kbacompany owns routes, business data, permissions and navigation model.
- BMS CRUD: ListView → DetailView → EditView → Related Records / Actions → History.
- UUID is machine identity, code is stable human reference, and approved/published revisions are immutable.
- No new domain storage or workflows are created by this release.
- Existing company UUID scope and POST/sesskey rules remain in force.

## Changed code
- quality_center.php: accessible section navigation (`nav`, aria-current), explicit active registry heading, responsive data table and semantic empty state.
- No changes to repository/service/SQL, permissions, routes, XMLDB or external interfaces.

## Live acceptance matrix (all NOT RUN)
| Area | Desktop | Mobile | Roles / company scope | Empty / error / keyboard |
| --- | --- | --- | --- | --- |
| Desktop / App Shell | NOT RUN | NOT RUN | NOT RUN | NOT RUN |
| Company / Organization / Employee | NOT RUN | NOT RUN | NOT RUN | NOT RUN |
| Tasks / Battle Plan | NOT RUN | NOT RUN | NOT RUN | NOT RUN |
| Meetings / Calendar | NOT RUN | NOT RUN | NOT RUN | NOT RUN |
| Metrics / Management cycles | NOT RUN | NOT RUN | NOT RUN | NOT RUN |
| Communication Center | NOT RUN | NOT RUN | NOT RUN | NOT RUN |
| Documents / Policies / Quality Center | NOT RUN | NOT RUN | NOT RUN | NOT RUN |
| Learning integration / Company context | NOT RUN | NOT RUN | NOT RUN | NOT RUN |

## Open scope from rc20
Nonconformity registry is NOT verified as delivered. A3 is not a canonical Nonconformity. The original RC20 requirement must be explicitly resolved before FINAL. RC21 does not silently add, rename or defer it.

## Release gates
- Static QA: PHP lint for all files, JS parse, language-key parity and duplicates, XML parse, archive CRC.
- LIVE: browser rendering / responsive layout / keyboard and screen reader checks NOT RUN.
- FINAL gating: RC22 security, RC23 performance, RC24 full regression, backup/restore and rc20 scope decision.
