# KBA Gamification 1.7.0 — Rewards Marketplace API

This release prepares Moodle to be the source of truth for KBA Points while `store.kzn.mba` / Bitrix24 owns catalogue merchandising, cash payments, stock operations and fulfilment.

## Safe two-phase flow

1. `check_reward_eligibility(userid, rewardid)` — verify balance, level and current availability.
2. `reserve_reward(...)` — protect KBA Points and finite stock for 1–60 minutes (default 15 minutes).
3. The external store creates its Bitrix24 order.
4. `commit_reward_reservation(...)` — debit KBA Points exactly once and create a Moodle mirror order linked by `externalref`.
5. If checkout fails, call `cancel_reward_reservation(...)`; abandoned reservations expire automatically.

## Source of truth

- Moodle wallet `coins` remains the canonical points balance.
- Active reservations do not reduce the total balance, but they reduce `availablecoins`.
- Any other negative Coins transaction is blocked from consuming actively reserved points.
- Internal field/API key names continue to use `coins` for backward compatibility; Russian UI says **KBA Баллы**.

## Security defaults

The reservation write API is disabled by default. Enable Web services, the KBA Gamification Rewards API service, a dedicated restricted service user, HTTPS, and `externalreservationenabled` only when the trusted storefront integration is ready.
