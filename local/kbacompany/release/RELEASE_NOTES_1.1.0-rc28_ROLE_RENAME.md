# BMS24 RC28 — build2026101467 — Role name hotfix

- Client-facing role label: **Бизнес-инженер BMS** (EN: BMS Business Engineer).
- The previous standard role **Интегратор BMS24** is renamed in place at upgrade (where no new-name role already exists). Role IDs, grants, assignments and audit history remain untouched.
- New role seed templates use the new name; no default permissions are expanded and no automatic assignment occurs.
- If both names already coexist in one company, the old role is preserved to avoid merging role identities; review manually.
- Moodle site administrator permissions remain separate. No other business behaviour or schema change.
- Live upgrade / Moodle RBAC acceptance: NOT RUN.
