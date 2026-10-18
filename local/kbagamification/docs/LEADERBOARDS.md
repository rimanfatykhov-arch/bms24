# KBA Gamification 1.2 — Leaderboards

## Student view

A learner can open **KBA Gamification → Leaderboards** and select one of the courses where the learner has an active enrolment.

The learner can rank the course by:

- KBA XP;
- achievement count.

If the learner belongs to Moodle groups in the course, the learner can also restrict the ranking to one of those groups.

The page shows the configured Top N. If the learner is outside Top N, the learner's own full-course position is shown separately.

## Manager view

A user with `local/kbagamification:viewallleaderboards` can select any course and any Moodle group. When team leaderboards are enabled, managers also see Moodle groups ranked by combined XP.

## Privacy defaults

Current KBA Coins balance is not shown in leaderboards by default because Coins are spendable. Administrators can enable the Coins column in plugin settings.

## Configuration

Site administration → Plugins → Local plugins → KBA Gamification → Settings:

- Enable leaderboards
- Leaderboard size (5–100)
- Show Coins balance in leaderboards
- Enable team leaderboards
