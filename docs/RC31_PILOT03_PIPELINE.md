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

Initial head341159b passed STANDARD runs43471029216 and43471989444 (4:51/7:48), FULL43471029251 (37:02), and repaired Corporate43471029245. These results are historical evidence for that head; review fixes require their own CI links in PR86. No release readiness claim. Staging acceptance is NOT RUN; this CI pilot does not change staging.

## Release discipline and rollback

PR → unchanged CI/FULL → isolated staging acceptance → exact artifact manifest/SHA → owner approval → production → smoke. FAST/STANDARD alone never authorizes deployment. Revert this CI-only commit to remove the shadow lane; no DB migration or product rollback is needed.

## Profile design and explicit limits

| Lane | Scope | Target (not a measured guarantee) |
|---|---|---|
| FAST | Literal translation / literal translation diff (CSS remains FULL); static, lint, UI contract and isolated visual smoke still required | 1–3 minutes |
| STANDARD | First implementation: fixed Issue79 shadow benchmark, fresh install +62 focused tests + known ACL negative control + real HTTP/browser on both engines | 5–10 minutes |
| FULL | Existing fresh/historical upgrades, schema parity, complete BMS/Corporate suites, data preservation and security regressions | No reduced coverage |

The classifier is advisory: it cannot skip checks or authorize release. No generic functional-module STANDARD allowlist is approved in this pilot. Issue79 itself classifies FULL (actual PR81 base c2d9b0c1f72d84b3efccf62c84d41cd7eda52343 to head5f5f5dd8f11a24951c70c23f813af1186d3ce217,15 files). The fixed STANDARD benchmark runs alongside FULL even for this sensitive diff.

Optimizations: one fresh synthetic site and separate PHPUnit database per job, no historical origins in the additional feedback lane, two engines in parallel, Composer download-only cache keyed by pinned lock/PHP/OS. Existing FULL bootstrap and historical fixtures are unchanged. Mutable DB/site snapshots are deliberately not shared; a prebuilt immutable Moodle image is deferred because environment setup was small compared with migrations and it would add image maintenance/provenance scope.

Browser assertions cover compact/full text+file and file-only sends, exact download SHA256, same-company access, foreign-company404, anonymous303, forged sesskey rejection, oversized file error with no stored message, and exactly one DB message after double-click. HTML text occurrence count is not a row count because edit forms repeat the body. Fixture probes count canonical company-scoped rows directly.

Upload limit fixture uses PHP upload_max_filesize=64K/post_max_size=8M, matching the documented Pilot02 test. Existing production service calls get_max_upload_file_size() without site maxbytes; changing that business policy is out of scope. A site-maxbytes-only test was invalid for this historical contract and failed as expected; no product code was changed to make it pass.

Logs contain stage labels, wall and child-process CPU (not database server CPU), engine and tested merge SHA. Browser/packaging have separate timings. JUnit must contain testcases with no errors/failures/skips in STANDARD. Actor credentials, config.php, moodledata and HTTP server logs stay outside the uploaded evidence directory. Compare final run URLs and exact test identities in the PR before acceptance; repository prose is not evidence of a completed run.

Independent QA/security review remains required before merge. This Draft does not assert that review or staging acceptance has occurred. For subsequent small fixes: classify the actual diff, run shadow feedback and all existing FULL gates, examine exact-source artifact evidence, perform isolated staging acceptance, then request owner approval for the exact ZIP/SHA/host/window and rollback plan. Do not infer permission from a green STANDARD badge.


## Independent review follow-up

Independent QA reproduced a false green by mutating real successful browser observations: text rendering false and direct POST500 were not included in the verdict. The fix requires all text observations,303 for direct upload and a unique rendered attachment with verified download hash. Negative mutation tests are part of STANDARD CI, including optimized Python. Terminal browser JSON defaults FAIL and becomes PASS only after the contract, SQL and per-actor ACL checks.

Security review found no current FULL/release bypass: classification is advisory. Nevertheless CSS opacity/escaped-resource values were incorrectly labelled FAST; all CSS is now FULL. Browser contexts restrict navigation to loopback; API downloads validate exact HTTP origin before the request and refuse redirects. This is request-level containment, not an OS-level egress firewall. The original PHPUnit lists, FULL workflows, release policy and production/staging remain unchanged.

Independent AI review is not human owner approval. Review findings, final source SHA, rerun evidence and the approval card are maintained in PR86; no automatic merge or deployment is authorized.
