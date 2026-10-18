# AI Decision Log — v0.29.6.3

## Decision
Remove the separate `Инструменты BMS` menu section and merge its links into the framework section, renamed `Методология BMS`.

## Reason
The BMS tool library and JSON competency mapping are methodology-management functions and duplicated the conceptual scope of Framework Manager. The separate card increased visual density without adding a distinct workflow stage.

## Rejected option
Delete the tool-library and JSON links entirely. Rejected because the functions remain required; only the navigation grouping is redundant.

## Result
Five workflow sections remain: Client, Diagnostics, BMS methodology, Implementation and Analytics. Existing routes and permissions are unchanged.
