# BMS 0.6.9.7 — Balanced Scorecard Management

Build `2026092137`.

This release converts Strategy & BSC from a mostly configuration-oriented page into an operational BSC workspace. Strategic goals are grouped by the four BSC perspectives, assigned to accountable BMS Positions, measured by multiple existing canonical Indicators, and tracked against explicit target/range norms and canonical fact history. Goal health and trend are read models; no manual completion percentage is stored.

The goal detail view shows the current employee projection for the accountable position, indicator actual/target/trend/criticality/weight, and the execution branch sourced through the Administrative Scale and existing Battle Plan. The Strategic Map remains a projection over the same strategic goal records and existing acyclic causal links.

Ownership Intent / Strategy / Strategic Goal stable codes are now server-generated using the existing stable-code infrastructure. Existing historical codes remain unchanged.

Schema-neutral release: install.xml, access.php, services.php and privacy provider are unchanged. No parallel KPI/task storage, competency logic, learning ownership or ERP actual is introduced.
