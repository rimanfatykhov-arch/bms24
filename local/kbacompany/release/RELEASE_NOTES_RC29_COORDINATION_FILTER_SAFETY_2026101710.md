# RC29 build2026101710 — Weekly Coordination filter safety regression

Static hardening of CEO Overview when a requested historyuserid is absent from the canonical employee scope. Previously the normalized selected history userid was 0, causing the CEO summary to appear despite an explicit invalid employee filter. Now the view returns a translated warning and suppresses CEO rollup for that request. Existing company authorization, employee-scoped canonical source checks, unit/employee projection, and all write paths are unchanged. No DDL. Contract 0.8.3.

### Static regression and acceptance
- Requested employee 0 -> CEO conditional display is eligible only when no orgnode and there are scoped units.
- Requested accessible employee -> only personal history; no CEO summary.
- Requested inaccessible employee -> warning and CEO summary suppressed; no personal history and no foreign data.
- Unknown orgnode and cross-company UUID still rejected by canonical source services.
- Verify 4/8 week selector, unit links, KBA/Boost, mobile, GET-only semantics, and actual ACL with two companies on staging.

Live Moodle acceptance: NOT RUN.
