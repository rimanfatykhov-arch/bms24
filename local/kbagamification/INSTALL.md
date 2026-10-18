# Install / upgrade — KBA Gamification 1.6.0

## Upgrade from 1.5.0

1. Make a normal Moodle/database backup.
2. Site administration → Plugins → Install plugins.
3. Upload `KBA_Gamification_1.6.0.zip`.
4. Confirm replacement/upgrade of `local_kbagamification`.
5. Complete the Moodle database upgrade.
6. Purge caches if required by the hosting environment.
7. Open the user profile → **KBA Learning Profile**.

Build transition:

`2026081700 → 2026081701`

No database tables are added or removed in this release.

## Quick test

1. Open a test learner who already has XP and achievements.
2. Confirm XP, KBA Coins and achievements are unchanged after upgrade.
3. Open `/local/kbagamification/profile.php`.
4. Confirm the level progress bar and nearest goals are displayed.
5. Confirm recent XP/Coins transactions appear in the activity timeline.
6. Open Site administration → Plugins → Local plugins → KBA Gamification → Settings.
7. Toggle Coins visibility or activity history and confirm the profile responds.
8. Open an old `/local/kbagamification/index.php?userid=...` link and confirm it redirects to the Learning Profile.

## Theme integration

KBA Theme may use:

```php
\local_kbagamification\api::get_user_theme_badge($userid);
```

See `docs/STUDENT_PROFILE.md`.
