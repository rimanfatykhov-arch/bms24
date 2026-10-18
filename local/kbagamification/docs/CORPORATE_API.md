# KBA Gamification — Corporate Dashboard API

KBA Gamification 1.2 exposes a stable PHP API for other KBA Moodle plugins.
Corporate Dashboard should not query Gamification tables directly.

## User summary

```php
$summary = \local_kbagamification\api::get_user_summary($userid);
```

Returns the current XP, Coins, level, progress to the next level and achievement count.

## User achievements

```php
$achievements = \local_kbagamification\api::get_user_achievements($userid);
```

Returns the user's awarded achievements ordered from newest to oldest.

## Company leaderboard

Corporate Dashboard remains the owner of company-to-user membership. It should resolve the Moodle user IDs of the employees and pass them to Gamification:

```php
$rows = \local_kbagamification\api::get_company_leaderboard(
    $employeeuserids,
    20,
    'xp'
);
```

Supported metrics:

- `xp`
- `achievements`

Each row contains:

- `rank`
- `userid`
- `fullname`
- `xp`
- `coins`
- `achievementcount`
- `levelid`
- `levelname`

## Company summary

```php
$summary = \local_kbagamification\api::get_company_gamification_summary($employeeuserids);
```

Returns:

- `participants`
- `totalxp`
- `averagexp`
- `totalcoins`
- `achievementcount`
- `averageachievements`
- `leveldistribution` — ordered rows with `levelid`, `levelname` and `count`

`totalcoins` is an internal aggregate. Corporate Dashboard should decide through its own privacy/settings layer whether balances are shown to corporate managers.

## Course/group leaderboard

```php
$rows = \local_kbagamification\api::get_course_leaderboard($courseid, $groupid, 20, 'xp');
```

Use `$groupid = 0` for the entire course.

## Moodle group/team leaderboard

```php
$teams = \local_kbagamification\api::get_course_team_leaderboard($courseid, 20);
```

Each team row contains group rank, participant count, total XP, average XP and total achievements.

## Integration rule

Corporate Dashboard owns corporate structure and access control. KBA Gamification owns XP, Coins, achievements, levels and ranking calculations. This separation avoids hard dependencies between the plugins.

## 1.4.0 combined snapshot

KBA Corporate Dashboard can now request the complete widget payload with one internal call:

```php
$snapshot = \local_kbagamification\api::get_company_snapshot(
    $employeeuserids,
    20,
    'xp'
);
```

The result contains:

- `summary` — participants, total/average XP, Coins aggregate, achievement totals and level distribution;
- `leaderboard` — ranked employees with XP, level and achievement count;
- `metric` — effective ranking metric (`xp` or `achievements`).

For a separate deployment, version 1.4.0 also provides the restricted Web service function `local_kbagamification_get_company_snapshot`. Prefer the internal PHP API when both plugins run in the same Moodle instance.
