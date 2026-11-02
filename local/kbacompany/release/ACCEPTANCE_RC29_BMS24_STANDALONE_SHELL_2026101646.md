# Acceptance — RC29 build2026101646 BMS24 standalone owned shell

## Static checks
- [x] PHP syntax: changed PHP files PASS.
- [x] Version bumped to 2026101646.
- [x] Upgrade savepoint 2026101646 added; no DDL.
- [x] Owned page layout contract is `embedded`.
- [x] Legacy feature flag behavior preserved.

## Required live Moodle acceptance
- [ ] Install build2026101646 through Moodle Plugin Installer.
- [ ] Purge caches after upgrade.
- [ ] `owned`: workspace opens without Moodle/KBA top navigation.
- [ ] `owned`: no duplicate outer title `BMS24 — Система управления бизнесом`.
- [ ] `owned`: only one BMS24 header and one sidebar are visible.
- [ ] `owned`: user menu, Task action, messages and navigation still work.
- [ ] `owned`: tasks, recurring tasks, Battle Plan, employee card and process pages open inside the same shell.
- [ ] `owned`: mobile 390px has no horizontal overflow and mobile navigation is usable.
- [ ] `owned`: test under KBA Theme.
- [ ] `owned`: test under Boost.
- [ ] `legacy`: switch back to legacy and confirm previous Moodle-integrated rendering still works.
- [ ] Security: cross-company access remains blocked by existing service/capability checks.

## Release gate
Staging candidate until live acceptance is completed.
