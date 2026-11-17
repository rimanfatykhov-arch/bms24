# Communication Center E2 (Battle Plan discussion): exact source updates in the preservation gate

Bounded review of the protected files changed by E2. It is **not** runtime acceptance, owner approval, or permission
to publish.

Exact before/after LF-normalized SHA256 values: `communication-center-e2-source-preservation.json`. Base: `main`
`9736452` (= published #120 on #114). `before` of each file equals the previous reviewed `after`:
`communication_service.php` — the Communication Center cleanup follow-up; both lang files — the daily reports R0
follow-up (#120). Chain: PR102 → #110 → #113 → cleanup (#114) → R0 (#120) → E2; no link is reset.

| File | Intentional change | Preservation assessment |
| --- | --- | --- |
| `classes/local/communication_service.php` | `battleplan` added to `CONTEXT_KINDS` and `VIEWER_READ_KINDS`; one contract rule; docblock names Q9. | No other line changed; participation, edit/withdraw audit, files, notifications unchanged. Contract version unchanged. |
| `lang/en/local_kbacompany.php` | 6 strings appended after a new marker `// Communication Center E2 (2026-11-16): Battle Plan discussion (Q9).` | Existing strings and earlier blocks byte-identical. |
| `lang/ru/local_kbacompany.php` | Same 6 keys in Russian. | Same. |

Gate changes: one more exact-hash `reviewed_followup` entry in `tools/check_pr53_pr56_integration.py`; the evidence file
added to `FOLLOWUPS` in `tools/tests/test_pr102_source_preservation.py` (chain must link, every reviewed path still
rejects an unreviewed edit). `tools/tests/test_dailyreport_source_preservation.py` splits at the new E2 marker: the
published #120 file must be byte-identical up to it (`R0_AFTER` hash), the E2 block must be exactly the 6 listed keys
with no override of any earlier key; the mutation list gains an E2 key override. No path exemption, skipped checker,
manifest, schema or migration change.

Merge note: R1/R2 daily reports (#118/#119) also append lang strings and gate entries. Whichever lands second rebases and
re-chains its lang `before` on the other's `after`.
