# local_kbacompany 1.1.0-rc11 — Metrics editor UX

Build: `2026101227`

## Scope

- Reworked the metric editor into a full-width BMS24 card instead of the legacy two-column technical form.
- Added clear sections: metric passport, management parameters, description/method, BMS24 role, formula/source.
- Added compact metric summary chips for unit, period and desired direction.
- Main company metric is now a dedicated highlighted management block and badge in the card header.
- Preserved dedicated navigation to norms, facts, bindings, formula/source and history.
- Added a sticky save bar on desktop with a clear explanation of what is saved.
- Added responsive layout for tablet and mobile.
- No schema, capability, lifecycle or metric identity changes. UUID and historical plan/fact data are preserved.

## Live acceptance

NOT RUN. Validate on Moodle 4.1.22 with a real active metric, an archived metric, and mobile width.
