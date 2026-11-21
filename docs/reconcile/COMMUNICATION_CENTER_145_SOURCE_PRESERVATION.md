# #145 — Communication Center on Mustache: source preservation

**Scope:** reviewed source change record for `tools/check_pr53_pr56_integration.py`.
Not release approval and not runtime acceptance.

## What changed

The Communication Center page is rendered through Mustache templates instead of
hand-built HTML in `communication_center_ui_service.php` (9 new `templates/bms24_comm_*.mustache`,
`render_from_template` used 3 times).

Presentation only:

- no schema change, no `db/install.xml`, no `db/upgrade.php` step;
- no ACL, company scoping, file API, notification or live-update change;
- plugin version stays `2026111601`, because the release version is pinned by seven
  CI policy gates and changing it is a separate policy decision;
- no upgrade runs on install for this package.

## Pinned files updated

Two language files and one stylesheet are bound by exact LF-normalised hashes in
`tools/check_pr53_pr56_integration.py`.

| File | Change |
|---|---|
| `local/kbacompany/lang/ru/local_kbacompany.php` | appended 28 `communicationux2_*` strings at the very end of the file, after every previously pinned block |
| `local/kbacompany/lang/en/local_kbacompany.php` | same 28 strings, appended at the end |
| `local/kbacompany/styles.css` | one scoped alignment rule appended: `.bms24-comm-iconbtn .bms24-comm-svg` |

New LF-normalised SHA256 values recorded in `reviewed_followup`:

```
lang/en  b7846695bdd8fa7bed201f102308c245725c5576e768b5ed3b88cf70ebf1aa0a
lang/ru  2436dd5c20ba4608c82bff1d7358efa89794a1866b0acc384e19bc18708615aa
styles   95563c9160349cb2f40047f092e78e77c446ee6113d48d13456250dfef271417
```

## Why the strings were needed

The templated centre references 28 `communicationux2_*` keys that existed in no
language file, so the UI would have rendered raw keys. They were introduced by the
refactor, not by a product decision; the wording mirrors the approved prototype from #122.

## Preservation guarantees retained

- Every earlier block stays byte-identical up to its next marker; the strings were
  appended after the UX-1 block, not inserted into a pinned region.
- RU and EN remain at parity: 9186 keys each, zero differences.
- The existing preservation test gained an explicit `C145_MARKER` / `C145_KEYS` block,
  the same way E2 and UX-1 were recorded.

## Verification

- `python tools/check_pr53_pr56_integration.py` → PASS
- `python -m unittest tools.tests.test_dailyreport_source_preservation` → 4 tests OK
- `python tools/check_reconcile_1879.py` → PASS
- `python tools/check_workflow_policy.py` → PASS
- `php -l` on the changed PHP, PHP 7.4.33 and 8.1.34 → 0 errors
