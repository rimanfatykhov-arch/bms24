# KBA Learning Profile — 1.6.0

The learner-facing entry point is:

`/local/kbagamification/profile.php`

Legacy links to `/local/kbagamification/index.php` are preserved and redirect to the new profile.

## Profile sections

- current KBA level;
- XP and progress to the next level;
- optional KBA Coins balance;
- achievement gallery with earned/locked states;
- three nearest achievement goals;
- three most recently earned achievements;
- optional XP/Coins activity timeline;
- quick links to leaderboards and KBA Rewards when enabled.

## Administration settings

Site administration → Plugins → Local plugins → KBA Gamification → Settings

The learner profile can independently control:

- Coins visibility;
- level progress visibility;
- locked achievement visibility;
- activity history visibility;
- number of recent activity rows.

These settings only affect presentation. They do not change awarding rules or balances.

## KBA Theme integration

Themes and other same-instance KBA plugins should not read KBA Gamification database tables directly.

Use:

```php
$badge = \local_kbagamification\api::get_user_theme_badge($userid);
```

or the compatibility helper:

```php
$badge = local_kbagamification_get_user_badge_data($userid);
```

Returned data includes level, XP, optional Coins presentation flag, achievement count and the canonical profile URL.
