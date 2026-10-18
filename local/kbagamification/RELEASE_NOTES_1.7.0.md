# KBA Gamification 1.7.0 — Rewards API

- Two-phase KBA Points reservation/commit/cancel API for `store.kzn.mba` and Bitrix24.
- Active reservations reduce available spend without changing the canonical wallet balance.
- Finite reward stock is protected during checkout and restored on cancellation/expiry.
- Idempotency key support prevents duplicate reservations/charges.
- External order reference is stored on Moodle mirror orders.
- Automatic scheduled cleanup of expired reservations every 5 minutes.
- Additive API fields: `reservedcoins` and `availablecoins`.
- Existing Moodle Rewards Store and immediate purchase flow remain compatible.
