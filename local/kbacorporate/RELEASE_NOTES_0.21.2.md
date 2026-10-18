# KBA Corporate Dashboard 0.21.2

Build: `2026090241`

## Added

- Target maturity level for every BMS audit criterion.
- Immutable target snapshot in every created implementation audit.
- Development routes from a criterion to one or more company BMS tools and Moodle courses.
- Automatically generated implementation plan showing unassessed criteria, gaps, priorities and routes.
- Comparison with the preceding confirmed audit of the same tool.

## Point A migration

Existing and new `BMS-AUD-CHK-001` criteria receive target level `7`. Existing Point A audit items also receive a target snapshot of `7` during upgrade.

## Gap rules

`gap = max(target - actual, 0)` when both values exist. Unassessed criteria remain separate from numeric zero. Priorities are critical for gaps of 5+, core for 3–4, and additional for 1–2.

## Upgrade

Install the full ZIP over the existing plugin directory through Moodle plugin installation. Do not uninstall the plugin. Moodle upgrades the database to `2026090241`.
