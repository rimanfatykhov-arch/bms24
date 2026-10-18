# KBA Gamification 1.6.2 — Russian KBA Points terminology

- Replaced Russian user-facing `KBA Coins` terminology with **KBA Баллы**.
- Removed hard-coded `KBA Coins` / `Coins` labels from Moodle pages and Mustache templates; labels now use language strings.
- Updated reward catalogue, orders, achievements, profile, leaderboards, settings, validation messages and privacy descriptions in Russian UI.
- **No database schema changes.** Internal fields such as `coins`, `costcoins`, `rewardcoins`, API keys and transaction currency codes remain unchanged for compatibility.
- Safe in-place upgrade from 1.6.1.
