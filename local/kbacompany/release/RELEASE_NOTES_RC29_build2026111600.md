# RC29 candidate build2026111600 (not released)

ADR-0009 R1: saved daily reports. Five additive tables (`dreport`, `dreportver`, `dreportlink`, `dreportrev`, `dreportlog`) created from install.xml; no data backfill and no change to existing tables. Server switch `local_kbacompany/dailyreports_enabled` defaults to off: the page stays the read-only day summary until an administrator enables it. Privacy provider covers the new tables. Pairs with Corporate 2026111100. HOLD until CI, Moodle PHPUnit, dual-engine upgrade and browser acceptance; never deployed by this change.
