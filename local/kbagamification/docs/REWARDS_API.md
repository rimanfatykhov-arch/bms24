# KBA Rewards integration contract — 1.3

KBA Gamification remains the source of truth for Coins, catalogue availability and reward orders.

## Read catalogue

```php
$items = \local_kbagamification\api::get_rewards_catalog($userid);
```

If `$userid` is supplied, each item includes current eligibility based on Coins, stock and minimum level.

## Read user order history

```php
$orders = \local_kbagamification\api::get_user_reward_orders($userid);
```

## Important boundary

Version 1.3 intentionally exposes **read-oriented internal PHP integration methods** only. A public REST endpoint for `kzn.mba` is not enabled yet. External purchasing must not write directly to the plugin tables or debit Coins outside `reward_manager`/`engine`.

The planned 1.4 integration should authenticate `kzn.mba`, expose a narrow service contract and optionally sync order workflow to Bitrix24.
