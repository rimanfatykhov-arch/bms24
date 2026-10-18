## 1.7.1 — KBA Награды
- Russian UX renamed from KBA Rewards to **KBA Награды**.
- **Мои заказы** renamed to **Мои награды**.
- Internal API/DB/URL identifiers remain unchanged for compatibility.
- No database schema changes.

# Changelog

## 1.7.0 — Rewards API
- Added marketplace reservation/commit/cancel flow for KBA Points and stock.
- Added reservation lifecycle API, external order references and scheduled expiry cleanup.
- Protected reserved points from concurrent/legacy spend operations.

## 1.6.5 — 2026-08-16

- Engagement UX API: latest achievement for user/cohort and activity anchor.
- No database schema changes.

# Changelog

## 1.6.4 — API Hotfix
- Fixed `get_user_level_progress()` integration used by KBA Theme Personal Cabinet.
- Added `level_manager::get_level()` helper expected by the public Gamification API.
- No database tables or user balances are changed.

# 1.6.3 — Personal Cabinet Integration
- Embedded learner Gamification summary API/widget for the existing KBA Personal Cabinet.
- Separate global KBA Profile navigation is disabled by default.

# Changelog

## 1.6.2 — Russian KBA Points terminology

- Russian UI now uses **KBA Баллы** instead of KBA Coins/Coins.
- Localized previously hard-coded coin labels in profile, achievements, rewards and orders.
- Database/API field names remain unchanged.

# KBA Gamification Changelog

## 1.6.0 — Learning Profile & UX

Build `2026081701`.

- Added a dedicated KBA Learning Profile instead of redirect-only `profile.php`.
- Kept `/index.php` as a compatibility redirect for existing links/bookmarks.
- Added profile hero with KBA level, XP, optional Coins and achievement count.
- Added configurable level progress display.
- Added visual achievement gallery and locked-achievement progress.
- Added nearest goals and recently earned achievements.
- Added XP/KBA Coins activity timeline with configurable row limit.
- Added profile visibility settings for Coins, level progress, locked achievements and history.
- Added `api::get_user_theme_badge()` and `local_kbagamification_get_user_badge_data()` for KBA Theme integration.
- Updated user navigation and achievement-notification links to the canonical profile URL.
- No schema changes; all 1.5.0 data is preserved.

## 1.5.0 — Student Experience

- Added stable `profile.php` entry point.
- Added API helpers `get_user_activity_history()`, `get_user_level_progress()` and `get_user_gamification_profile()`.
- Preserved all XP, KBA Coins, levels, achievements, transactions, rewards and orders from 1.4.0.
- Build `2026081700`, compatible with Moodle 4.0+.

## 1.4.0 — Corporate & Rewards API

- Added restricted Moodle Web services for KBA Rewards and corporate gamification data.
- Added corporate snapshot API and external API kill switches.
- Added dedicated service capabilities and API documentation.

## 1.3.0 — KBA Rewards Store

- Added rewards catalogue, KBA Coins purchases, stock, level requirements and order workflow.
- Added automatic Coins refunds on order cancellation.

## 1.2.0 — Leaderboards

- Added course, group, team and company-oriented leaderboards.

## 1.1.x — Achievements

- Added configurable achievements, progress, bonuses, notifications and reports.

## 1.6.1 — Navigation UX
- Added a discoverable **KBA Profile** entry to Moodle global navigation.
- Added grouped KBA links to user-profile navigation.
- Added a consistent learner navigation bar across profile, leaderboard, Rewards and order history.
- Added a direct **Achievements** navigation item to the profile achievement gallery.
- Separated administrator actions visually from learner navigation.
- Added a site setting to enable/disable the Moodle navigation entry.
