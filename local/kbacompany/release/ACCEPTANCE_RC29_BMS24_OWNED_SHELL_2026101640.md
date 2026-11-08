# BMS24 RC29 build2026101640 — Acceptance for plugin-owned App Shell

### Local checks (not Moodle runtime)
- [x] Source based on actual build2026101639 and isolated into next build.
- [x] Plugin-owned shell, 9 Mustache templates, scoped CSS token file, setting default legacy.
- [x] PHP lint / RU+EN locale parity / XMLDB static validation.
- [x] PHP-only legacy→owned→legacy/allowlist smoke.
- [x] Static CSS scope audit, Chromium standalone mock 1440+390 with no body horizontal overflow.
- [x] CRC + SHA256 verification of deliverable archives.

### Required staging checks — NOT DONE
- [ ] Upgrade live staging Moodle DB build1639→1640 (confirm no structural change, no warnings).
- [ ] `legacy` mode KBA Theme menu and all routes match baseline.
- [ ] `owned` mode KBA theme AND Boost theme: no fatal / duplicate page header problems; all new Mustache templates resolve.
- [ ] Main workspace content, architecture flow/tree and 5+ ordinary page-shell routes function at desktop 1440, tablet 768 and mobile 390.
- [ ] Dropdown navigation keyboard + screen reader names, focus trap not introduced, touch interaction.
- [ ] Two companies: direct GET/POST navigation ACL and no links exposing protected records.
- [ ] Tasks: create/edit/status/reviewer, personal vs position-bound, recurring generator, coverage, approved Battle Plan, metrics, calendar unchanged.
- [ ] Confirm no KBA Theme CSS/global namespace overrides (`.bms24-app` CSS confined).
- [ ] Flip owned→legacy; purge caches; verify precise original visual behavior.
- [ ] Browser console, PHP errors, MySQL errors, performance; user acceptance sign-off.

**Release decision**: NO-GO for production until staging E2E passes. No automatic deployment performed.
