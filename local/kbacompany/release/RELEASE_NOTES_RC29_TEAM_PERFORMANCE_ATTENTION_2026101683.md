# RC29 build 2026101683 — Team Performance Attention UX

- Adds an explainable management-attention layer to the existing team productivity/effectiveness view.
- Separates four states: requires attention, insufficient data, improving, stable.
- Does not introduce an employee score or ranking.
- Attention reasons are derived only from canonical task/metric signals and week-over-week deltas.
- Existing Battle Plan access control and company isolation remain authoritative.
- No DB schema changes.
