# Communication Center E2 (Battle Plan discussion): exact source updates in the preservation gate

Bounded review of the protected files changed by E2. It is **not** runtime acceptance, owner approval, or permission
to publish.

Exact before/after LF-normalized SHA256 values: `communication-center-e2-source-preservation.json`. Base: current
`main` after daily reports R1/R2 (#118/#119, merged into this branch). `before` of each file equals the latest reviewed
`after` over PR102 and every follow-up in `FOLLOWUPS` order (for all three files that is the R2 follow-up).
Chain: PR102 → #110 → #113 → cleanup (#114) → R0 (#120) → R1 → R2 → E2; no link is reset.

| File | Intentional change | Preservation assessment |
| --- | --- | --- |
| `classes/local/communication_service.php` | `battleplan` added to `CONTEXT_KINDS` and `VIEWER_READ_KINDS`; one contract rule; docblock names Q9. | No other line changed; participation, edit/withdraw audit, files, notifications unchanged. Contract version unchanged. |
| `lang/en/local_kbacompany.php` | 6 strings appended after a new marker `// Communication Center E2 (2026-11-16): Battle Plan discussion (Q9).` | Existing strings and earlier blocks byte-identical. |
| `lang/ru/local_kbacompany.php` | Same 6 keys in Russian. | Same. |

Gate changes: one more exact-hash `reviewed_followup` entry in `tools/check_pr53_pr56_integration.py`; the evidence file
added to `FOLLOWUPS` in `tools/tests/test_pr102_source_preservation.py` (chain must link, every reviewed path still
rejects an unreviewed edit). `tools/tests/test_dailyreport_source_preservation.py` splits at the new E2 marker: the
R2 file (main after #119) must be byte-identical up to it (`R2_AFTER` hash), the E2 block must be exactly the 6 listed keys
with no override of any earlier key; the mutation list gains an E2 key override. No path exemption, skipped checker,
manifest, schema or migration change.

Merge note: R1/R2 (#118/#119) landed first; `origin/main` was merged into this branch (no rebase), the E2 lang block was
re-appended after the R2 block and every hash re-chained on R2.
