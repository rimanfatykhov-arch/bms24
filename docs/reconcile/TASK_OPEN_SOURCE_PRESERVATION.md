# Task opened event: exact source updates in the PR53/PR56 preservation gate

This is a bounded review of the two protected files changed by the "task opened" History event.
It is **not** runtime acceptance, owner approval, or permission to publish.

The exact before/after LF-normalized SHA256 values are in `task-open-source-preservation.json`.
`before` equals the PR102 reviewed `after` value and the content at base
`dfd90535ea90d13918fce0c2707cbd5946c406f2` (#103 did not change these files).

| File | Intentional change | Preservation assessment |
| --- | --- | --- |
| `lang/en/local_kbacompany.php` | Add `taskcenter_action_opened`, `taskcenter_history_opened`; reword `tui_history_help` (openings are listed separately and do not mean reading). | Other strings unchanged; RU/EN key parity retained. |
| `lang/ru/local_kbacompany.php` | Same three keys in Russian. | Other strings unchanged; key parity retained. |

The gate adds one more exact-hash entry; no path exemption, skipped checker, manifest, schema or
migration change. `tools/tests/test_pr102_source_preservation.py` now requires this follow-up to chain
from the PR102 `after` hashes and still proves that any further unreviewed edit fails.
