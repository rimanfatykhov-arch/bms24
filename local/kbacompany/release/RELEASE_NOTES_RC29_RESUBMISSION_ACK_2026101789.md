# BMS24 RC29 build2026101789 — resubmission warning

Returning plans that have outstanding reviewer remarks now show an explicit warning before resubmission. The employee must check a second confirmation checkbox acknowledging that remarks remain unaccepted. The server checks the same condition for POST submissions and rejects attempts to skip confirmation. No automatic status change to correction events; existing submit and reviewer permissions remain authoritative. The warning also applies to self-approval if applicable. No database schema changes.

QA: static verification only, production Moodle acceptance not run.
