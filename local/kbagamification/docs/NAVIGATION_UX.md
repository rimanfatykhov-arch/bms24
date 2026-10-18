# KBA Gamification 1.6.1 — Navigation UX

## Purpose

Learners should not need to know direct URLs such as `leaderboards.php`.

## Entry points

When enabled, KBA Gamification adds a **KBA Profile** entry to Moodle global navigation. Available child links are permission/configuration aware:

- My profile
- Leaderboard
- Rewards
- My orders

The user profile navigation receives the same KBA group.

Every main learner-facing KBA page also renders the same horizontal navigation bar. The **Achievements** item links to the achievement gallery inside the KBA Learning Profile.

## Permissions

Links are generated from existing KBA capabilities and settings. Disabled features and inaccessible sections are not shown.

## Administration

`Site administration → Plugins → Local plugins → KBA Gamification`

Setting: **Show KBA Profile in Moodle navigation**.
