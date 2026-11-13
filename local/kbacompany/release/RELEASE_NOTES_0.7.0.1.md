# BMS24 0.7.0.1 — Sidebar Navigation Optimization
Build: 2026092210

- User-facing brand changed from BMS to BMS24 in local_kbacompany language/UI strings.
- Sidebar reorganized into five semantic groups: Company, Architecture, People, Management, Improvements.
- Only the current context group is expanded by default.
- Employees and Learning are available inside the People group rather than as unrelated flat navigation entries.
- Core now supplies group key, active/expanded state and accessible toggle label to the presentation layer.
- Compact sidebar remains icon-first.
- Schema-neutral release; no access.php, services.php or install.xml changes.
- Machine names, component names, APIs and internal identifiers remain unchanged.

Requires KBA Theme 2.3.26 for accordion group interactions. Older supported themes still render the supplied links without business-rule changes.
