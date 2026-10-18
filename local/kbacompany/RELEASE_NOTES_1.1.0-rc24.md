# BMS24 1.1.0-rc24 / build2026101351 — Full Regression Candidate

Baseline: 1.1.0-rc23 / build2026101350, SOURCE archive.

Scope: release stabilization only. No new functionality, domain API changes, or database schema changes. This build adds a version/update savepoint and documents the available static regression checks.

Static checks: PHP lint 363/363 PASS; JavaScript syntax PASS; db/install.xml PASS; RU/EN keys 7575/7575, duplicates none; 15 static source contract assertions PASS. 80 PHPUnit test files discovered, but NOT EXECUTED without Moodle test runtime.

Open acceptance: RC22 company A/B and role/object/file/API isolation; RC23 query EXPLAIN and realistic performance benchmarks; RC21 KBA Theme visual shell; full Moodle PHPUnit/Behat end-to-end; backup/restore, upgrade and rollback rehearsal; live user scenarios. None is treated as passed.

Release status: RC24 candidate only; 1.1.0 FINAL NOT READY.
