# KBA Gamification 1.4.0 — External API

Version 1.4.0 adds two pre-built Moodle Web service declarations. Both services are **disabled by default** and restricted to explicitly assigned service users.

## Services

### KBA Gamification Rewards API

Shortname: `kba_gamification_rewards_api`

Functions:

- `local_kbagamification_get_rewards_catalog`
- `local_kbagamification_get_user_summary`
- `local_kbagamification_get_user_achievements`
- `local_kbagamification_get_user_reward_orders`
- `local_kbagamification_create_reward_order`

Required capability: `local/kbagamification:externalrewards`

### KBA Gamification Corporate API

Shortname: `kba_gamification_corporate_api`

Functions:

- `local_kbagamification_get_company_snapshot`

Required capability: `local/kbagamification:externalcorporate`

## Security model

There are several independent gates. All must allow the request:

1. Moodle Web services must be enabled.
2. REST protocol (or another configured protocol) must be enabled.
3. The selected KBA service must be enabled.
4. The service is restricted to explicitly authorised users.
5. The service user must have the corresponding KBA capability.
6. `KBA Gamification -> Enable external API` must be enabled.
7. Order creation additionally requires `Allow external reward order creation`.
8. Corporate Coins are hidden by default and require `Expose Coins through the corporate external API`.

Use a dedicated service account. Do not use a site administrator token in kzn.mba.
Use HTTPS only and keep the token exclusively on the server side.

## Corporate Dashboard integration

For plugins running inside the same Moodle installation, prefer the internal PHP API instead of HTTP:

```php
$snapshot = \local_kbagamification\api::get_company_snapshot(
    $employeeuserids,
    20,
    'xp'
);
```

`KBA Corporate Dashboard` remains responsible for company membership and passes only the Moodle user IDs belonging to that company. KBA Gamification remains responsible for XP, levels, achievements and ranking.

## Future kzn.mba storefront

The external Rewards service is intended for the future server-side integration of kzn.mba. The public browser must never receive the Moodle service token.

Recommended flow:

`Browser -> kzn.mba backend -> Moodle REST API -> KBA Gamification`

Before production use, keep external order creation disabled and test the read methods first.
