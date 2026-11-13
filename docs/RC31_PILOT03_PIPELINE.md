# RC31 Pilot03: additive STANDARD benchmark

Draft implementation for Issue82. No product files or release gates changed.

- Base main: 4e5c7f31797f7788bb317916f913094d64b5c26e.
- Production read-only: BMS/Corporate2026111100,1797 BMS files,tree SHA256 474201a12e2e06545fea2683dd2ed3ddf20566f049c8a29a17e0a7d1d6e0de22.
- Existing FULL defaults and workflow triggers remain unchanged. STANDARD is a fixed Issue79 shadow benchmark, never permission to publish.
- Historical migrations and full suites are explicitly outside STANDARD and remain mandatory in FULL.
- Exact negative control restores PR67 renderer in disposable Moodle, requires unchanged non-manager assertion to FAIL, then restores and hashes candidate bytes.
- Fresh random database names and synthetic actors only; private credentials/config/HTTP logs are excluded from artifact upload.
- FAST classifier is advisory and narrow. Unknown, structural, policy, test and sensitive changes fall back to FULL. No general STANDARD module allowlist is approved yet.
- Main ruleset25353580 currently requires `checks` and `Retained PHP7.4 syntax compatibility`; FULL remains a release-policy requirement. Ruleset is not changed by this PR.

## Measurements

Old run43412388734: MariaDB harness26m41s; Percona harness35m04s. Composer installation3s for each. Percona setup before harness65s, MariaDB37s. Whole-run37m22s includes scheduling overhead. Fine-grained phases were not independently timestamped; new harness emits labels, wall/child CPU, engine and SHA without command arguments.

New runs: pending. No speedup or release readiness claim. Staging acceptance is NOT RUN; this CI pilot does not change staging.

## Release discipline and rollback

PR → unchanged CI/FULL → isolated staging acceptance → exact artifact manifest/SHA → owner approval → production → smoke. FAST/STANDARD alone never authorizes deployment. Revert this CI-only commit to remove the shadow lane; no DB migration or product rollback is needed.
