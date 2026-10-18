# KBA Connect v5.0.25 — Bitrix24 Funnel & Stage Selector

## Change

Administrators no longer type `C3:EXECUTING` or other STAGE_ID values manually.

KBA Connect now loads real deal funnels and stages from Bitrix24:

- `crm.category.list` for deal pipelines;
- `crm.status.list` for stages of every pipeline.

The UX follows the proven v0.4.6 `funnel_manager` / `rules.php` model.

## Admin flow

KBA Connect → Воронки и стадии Bitrix24

1. Click **Загрузить / обновить воронки из Bitrix24**.
2. KBA Connect displays each funnel.
3. Select one or more real stages with checkboxes.
4. Click **Сохранить выбранные стадии**.

The existing production filter still stores the final result in
`v5_allowed_stages`, so the execution engine remains backward compatible.

## Safety

- No automatic inbound is enabled by this update.
- Stage refresh only performs read-only Bitrix24 REST calls.
- Selecting stages does not itself execute deals.
