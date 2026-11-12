# PR71 S12 acceptance — HOLD

Runtime source: `d305f9469de4236a8259fcaa4de39227747b24c1`.
Base PR69: `ac7148f9c7a8f6d524c8a67896eec9ca2a31326f`.
Paired versions: BMS2026111100 / retained Corporate2613-derived2026111100.
ADR-0006 implements the owner's approved policy. No merge or deployment approval.

## Actual results, 2026-11-11

| Check | MariaDB 10.11.19 | Percona 8.0.46-37 |
|---|---|---|
| Isolated actual-backup baseline BMS1878 / Corporate2613 | PASS | PASS |
| Upgrade to paired1100 and repeated upgrade | PASS | PASS |
| Original IDs retained, original BMS/Corporate row hashes unchanged | PASS | PASS |
| 4,242 nonempty file contents verified against file hashes | PASS | PASS |
| Old session: revoked company BMS/Corporate plan/member pages denied | PASS | PASS |
| New session: same company pages denied | PASS | PASS |
| Valid-session POST denied in revoked company; positive POST in second company | PASS | PASS |
| Plan/member history retained; sync cannot reopen; explicit reopen works | PASS | PASS |
| Browser assertions, including before/revoke/reopen and second-company controls | 30/30 PASS | 30/30 PASS |
| Full BMS/Corporate PHPUnit on final runtime SHA | NOT RUN | NOT RUN |
| Fresh/upgrade schema parity on final runtime SHA | NOT RUN | NOT RUN |
| Complete file/API/export HTTP revocation matrix | NOT RUN | NOT RUN |

The backup had704 tables; candidate has705 after the additive revocation table.
No old IDs disappeared. Repeating upgrade left the compared hashes unchanged.
The original BMS/Corporate row comparison covers original columns, normalising
DECIMAL trailing zeros. It does not claim that every Core row is unchanged:
config, plugin config, course and scheduled-task metadata changed; logs/outbox grew.
Exact counts are in `S12-PR71-EVIDENCE.json`. Per-column explanation of all Core
metadata changes and complete physical-schema equivalence remain outside this PASS.
The browser preservation check hashes synthetic plan/member records; full historical
business-row and file-content checks were made separately before synthetic fixtures.

Both recovery pairs are stopped. Staging container IDs and start timestamps match
the pre-test inventory. Production was only an SSH relay using the owner's permanent
key; no production configuration/data/code or staging configuration/data/code changed.

## Before/after and unsuccessful runs

PR69 reproduced S12 on MariaDB: close_membership left old-session BMS and Corporate
self-plan HTTP200. Calling destructive Corporate delete_member made the routes deny
but deleted the plan. PR71 never calls that deletion: both old and new sessions deny
with HTTP404 while the exact synthetic plan and Corporate membership remain.

First PR71 runtime58a20ad CI on MariaDB: fresh/upgrade/data/schema checks PASS,
Corporate full suite PASS, BMS634 tests with3 errors +1 failure, all in new S12 tests.
Causes: duplicate task fixture keys; missing capability language label; expected
capability exception where an already-revoked user gets companynotavailable;
string/integer context-ID mismatch. These were corrected without changing indexes,
skipping tests, or weakening access expectations. A standalone curator test double
was extended for the new deny contract, adding four negative/positive assertions.

The final source's GitHub jobs did not start: GitHub reported failed recent payments
or a spending limit. This is infrastructure failure, not a test PASS. Earlier suite
results are not assigned to the final SHA. The original faulty fixture remains visible
in git history; the final test uses separate real audit items.

Two remote browser attempts failed before login (private-file permissions, then
Chromium lookup under an unmapped UID). They are not counted as acceptance. The final
short sessions used the existing browser image as root, all capabilities dropped
except DAC_OVERRIDE for the private synthetic evidence mount. Network remained the
recovery database's `none` namespace, with no host ports and no production data mount
in the browser. Resource limits and a guard stopped only named recovery containers.

## Reproduction and source identity

`tools/tests/s12_clone_fixture.php` refuses non-CLI execution, absent fixture opt-in,
non-loopback wwwroot, wrong database name, or versions other than paired1100. It
creates synthetic accounts/companies only and writes credentials outside Git.
`tools/tests/s12_browser_acceptance.py` uses the current session's sesskey for POSTs.
The positive company-B POST must additionally be verified by `state-check.json`
showing its dedicated control plan active; HTTP200 alone is insufficient.
Run phases before → revoke → old → new → explicit reopen → reopen, one engine at a
time, in the preflight-verified isolated recovery network. Keep the private actor and
session files on the test host; never attach them to CI or PRs.

Corporate materialisation preserves all830 base files and verifies the complete
postimage manifest. Runtime plugin directories were compared byte-for-byte to the
reviewed source/materialisation after the test. Canonical Corporate is a diagnostic
line and is not the retained2613 release artifact.

| Artifact | SHA256 |
|---|---|
| BMS2026111100 ZIP | `ac395023cbf6e1b032482e542c07ccb9885b8c1aa1fa00e49ca20875f64ca432` |
| Corporate2026111100 ZIP | `5b7b6aacf862b200c2462cc2aa9dbfae661ff065c2eb3f45fe44e6d602ace6fb` |
| Corporate full postimage tree | `0a47997812a092f20ebb5f4c53a246ea3ba40b793c542719922550952ecd6060` |

Independent source review confirmed closure of the UUID-alias and department-target
findings on d305f94. This was scoped review, not an assertion that every Corporate
route was exhaustively audited.

## Remaining acceptance and rollback

Restore GitHub Actions billing availability, then rerun the final head's focused and
full dual-engine suites and fresh/upgrade schema comparison. Complete file/API/export
HTTP revocation checks and the remaining PR69 S01–S15 acceptance. Do not mark RC30 READY.

For rollback, restore a consistent verified pre-upgrade database, code and moodledata
set in isolation. Do not downgrade only plugin code: older versions ignore active
revocations. A retry restored MariaDB to the original backup's704 exact table hashes;
one difference from a later intermediate snapshot was its extra synthetic session,
not missing original backup data. No production rollback/upgrade was run or authorised.
