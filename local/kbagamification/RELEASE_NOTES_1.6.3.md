# KBA Gamification 1.6.3 — Personal Cabinet Integration

- Added compact Gamification widget for the existing KBA Personal Cabinet.
- Shows current level, XP, KBA Баллы, achievements and progress to the next level.
- Adds direct links to achievements, leaderboard and rewards.
- Keeps KBA Learning Profile as the detailed drill-down page.
- Disables the separate global KBA Profile navigation by default to avoid duplicate personal cabinets.
- No database field names or API keys were renamed.
- Requires a theme that consumes `api::render_personal_cabinet_widget()`; KBA Theme 2.2.5 adds this integration.
