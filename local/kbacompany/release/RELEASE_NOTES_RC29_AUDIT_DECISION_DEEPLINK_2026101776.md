# BMS24 RC29 build2026101776 — Audit decision deep links

- In group-operation audit details, each existing decision links directly to its weekly coordination record.
- Link target is resolved exclusively from a DB record checked against the current company UUID; fabricated or missing record IDs remain plain text.
- Existing management access validation remains in place at registry entry and target coordination page.
- Uses canonical `week` + `decisiontask` route and `#bpwc-focused-decision` focus anchor.
- No schema modifications. Static validation only; live Moodle acceptance NOT RUN.
