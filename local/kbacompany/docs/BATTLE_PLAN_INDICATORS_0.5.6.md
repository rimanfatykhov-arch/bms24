# Battle Plan Indicator Projection — v0.5.6

## Source of truth

- Indicator: `local_kbacompany_stat`
- Position link: `local_kbacompany_statbind`
- Fact history: `local_kbacompany_statval`
- Norm revisions: `local_kbacompany_statnorm`
- Condition policy: `local_kbacompany_statpolicy`
- Manager condition decisions: `local_kbacompany_stateevt`
- Execution: `local_kbacompany_task`
- Battle Plan reference to Task: `local_kbacompany_bplanitem`

No Battle Plan-owned indicator or fact object is introduced.

## Historical rule

When a plan for week `W` is opened, the projection selects up to the last 52 canonical periods ending at `W` (not the latest 52 periods relative to today). The projection resolves the norm and condition policy effective for their relevant periods; trend and automatic-condition calculations therefore cannot consume facts from later weeks. A manager confirmation is matched only to the exact analytical fact period it confirms.

## Fact entry

An authorised plan owner/manager may enter the current-week fact from the Battle Plan. The write is append-only in `statval`. A second value for the same week creates a new revision and requires a correction reason.

## Legacy compatibility

Pinned `bpnorm` quota sets and their historical condition override UI remain available only when such a set is explicitly pinned to the plan. New canonical position-indicator projection is independent of this compatibility layer.
