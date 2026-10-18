# KBA Gamification 1.6.0 — Learning Profile & UX

Build: `2026081701`

Component: `local_kbagamification`

Compatibility: Moodle 4.0+

Version 1.6.0 is an in-place upgrade from 1.5.0. It preserves all existing XP, KBA Coins, levels, achievements, transaction history, reward catalogue and reward orders.

## New in 1.6.0

- dedicated **KBA Learning Profile** at `/local/kbagamification/profile.php`;
- legacy `/index.php` safely redirects to the new profile;
- profile hero with level, XP, optional KBA Coins and achievement count;
- level progress and remaining XP;
- visual achievement gallery with progress;
- nearest achievement goals;
- recently earned achievements;
- activity timeline for XP and KBA Coins;
- administrator display controls for profile sections;
- theme-friendly internal API `api::get_user_theme_badge()`;
- compatibility helper `local_kbagamification_get_user_badge_data()`;
- user navigation and achievement notifications now point to the canonical Learning Profile.

## Existing feature set preserved

- event-based XP and KBA Coins rules;
- immutable balance ledger;
- configurable levels;
- achievements with rarity and bonuses;
- course/group/company leaderboards;
- internal Corporate Dashboard API;
- KBA Rewards catalogue and orders;
- protected KBA Coins debit/refund workflow;
- restricted Moodle Web services for external integrations.

## Corporate Dashboard

Continue using the internal PHP API when Corporate Dashboard is installed on the same Moodle instance:

```php
$snapshot = \local_kbagamification\api::get_company_snapshot($employeeuserids, 20, 'xp');
```

For a compact learner identity card:

```php
$badge = \local_kbagamification\api::get_user_theme_badge($userid);
```

See `docs/STUDENT_PROFILE.md` and `docs/CORPORATE_API.md`.

## 1.6.1 Navigation UX

Learner-facing pages are now discoverable without memorising URLs. Moodle navigation includes **KBA Profile**, and the main learner pages share a consistent menu: **My profile · Achievements · Leaderboard · Rewards · My orders**. Links automatically respect capabilities and enabled features.


## 1.6.4 API hotfix
This release fixes the level progress API consumed by KBA Theme 2.2.6. Install over 1.6.3; XP, KBA Points, achievements, orders and settings are preserved.
