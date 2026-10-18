# KBA Corporate Dashboard 0.17.1.1 — XMLDB Hotfix

Release date: 2026-09-01

Build: `2026090101`

Public API: `1.15`

## Purpose

This is the complete `local_kbacorporate` plugin package. It can be uploaded through Moodle's **Site administration → Plugins → Install plugins** page and does not require manual extraction on the server.

The hotfix resolves both XMLDB errors reported during the v0.17.1 upgrade:

- the `competency_fk` / `competency_ix` collision in `local_kbacorp_rolecomp`;
- the overlong `external_uix` composite index in `local_kbacorp_extmap`.

## Upgrade procedure

Install this ZIP over the existing plugin and confirm the upgrade. Do not uninstall the plugin and do not delete its database tables. The migration checks whether each new table already exists, skips completed work and creates only what is missing before recording build `2026090101`.

## Remaining release gate

The package has passed the available structural, XMLDB and archive checks. PHP lint, Moodle PHPUnit and acceptance checks must still run on the target Moodle server because this build environment has no PHP/Moodle runtime.
