# local_kbacompany 0.7.4 — 360 Assessment

- Adds company-scoped 360 feedback cycles for employee competencies.
- Supports respondent groups: self, manager, peer, direct report and other.
- Individual responses are immutable after submission and never change the employee competency profile directly.
- Cycle closes only after its configured minimum number of completed responses.
- Closed cycles aggregate competency levels and transfer them into a draft formal Assessment Engine session (`feedback_360`).
- Formal Assessment Engine approval remains mandatory before any competency fact is projected.
- Adds privacy metadata/export/delete coverage for 360 cycles, respondents and scores.
- Additive schema: `local_kbacompany_360cycle`, `local_kbacompany_360resp`, `local_kbacompany_360score`.
