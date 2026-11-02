# Administrative Scale Strategy Foundation — 0.6.9.5

## BMS domain model

The release keeps the Administrative Scale as a management alignment model, not merely a parent-child task tree.

Methodology levels exposed in BMS:

1. Long-term Goal
2. Goal
3. Organizational Policy
4. Plan
5. Program
6. Decision / Order
7. Ideal State
8. Statistic
9. Valuable Final Product (ЦКП)

Execution-support objects are deliberately separated from methodology levels:

- Program Step
- Project
- Task

A Program is decomposed into Program Steps. A simple step can be executed as an order/task; a complex step can be expanded as a Project. Published task definitions enter the existing canonical Task Center and can be placed into an existing Battle Plan.

## Plan contract

A Plan is represented by three explicit management fields:

- **Situation** — current circumstances / non-optimal state / potential that makes the Plan necessary.
- **Goal of this Plan** — the result the Plan intends to achieve.
- **Plan** — the broad management approach and intentions for achieving the plan goal.

The Plan Goal is also projected into the existing `expectedresult` field for compatibility with downstream BMS services that already consume Administrative Scale expected results.

## Traceability

The intended trace is:

`Approved Owner Requirements → Long-term Goal → Goal → Organizational Policy → Plan → Program → Program Step → Project (when needed) → Decision/Order → Task → Battle Plan execution`.

Result verification remains distinct from execution:

`Ideal State + Statistic/Indicator + Valuable Final Product`.

A completed task is therefore execution evidence; it does not by itself prove that a strategic goal or final product has been achieved.

## Ownership reuse

- Owner requirements: `owner_service` published requirement package; private LPF answers are not exposed.
- BSC strategic goal: existing `strategy_service` / `local_kbacompany_stratgoal`.
- Organizational policy/directive: existing policy domain.
- Statistics: existing `local_kbacompany_stat` Indicator/Statistic domain.
- Valuable final product: existing `local_kbacompany_product` Product domain.
- Execution task: existing `local_kbacompany_task` Task Center.
- Weekly execution: existing Battle Plan projection.

No new duplicate registries are introduced.

## Isolation and access

All reads/writes are Company UUID-scoped before records are returned. Administrative Scale edits require existing management permission. Site admin continues to resolve company context without requiring Company membership. Theme remains presentation-only; route/menu ownership stays in `local_kbacompany`.
