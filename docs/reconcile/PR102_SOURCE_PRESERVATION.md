# PR102 — intentional source updates in the PR53/PR56 preservation gate

This is a bounded review of the five protected files changed by the task-discussion
follow-up to PR100. It is **not** runtime acceptance, owner approval, or permission
to publish. The release remains governed by FULL, BROWSER, Merge gate and the
separate production rehearsal/approval procedure.

The previous gate already used exact `reviewed_followup` LF-normalized SHA256
values for reviewed follow-ups (including PR81), while retaining the original
PR53/PR56 conflict map. PR102 adds a subsequent exact hash entry for these five
files. It does not add a directory exemption, skip the checker, change the parent
manifest, alter the schema checks, or change the migration chronology.

The exact before/after hashes and reviewed source basis are recorded in
`pr102-source-preservation.json`. The `before` values are those of PR100 head
`01af6405bcb7db73f67cb45fee837ba97b8c6ed3`; hashes normalize CRLF to LF only.

| File | Intentional change | Preservation assessment |
| --- | --- | --- |
| `classes/local/communication_service.php` | Retain the singular upload API as a wrapper; normalize multiple-upload input while preserving explicit `UPLOAD_ERR_NO_FILE` and optional MIME-type compatibility; validate the whole batch; commit one message and its attachments in one transaction. | Existing upload validator, append-message authorization, file-storage checks and attachment path remain in use. No company/RBAC condition is removed. |
| `lang/en/local_kbacompany.php` | Add five composer upload/error/remove strings. | Existing strings retained unchanged. |
| `lang/ru/local_kbacompany.php` | Add matching five Russian composer strings. | Existing strings retained unchanged; RU/EN key parity retained. |
| `styles.css` | Append the legacy overlay and selected-attachment-list rules. | Earlier rules remain; no data/auth/schema change. |
| `tests/communication_service_test.php` | Add malformed multipart, batch count, partial-upload, legacy no-file/optional-type and no-message-on-failure regressions. | Existing test methods and assertions retained; no skip/xfail added. |

Paths in the table are relative to `local/kbacompany/`.

## Gate verification

Run:

```text
python tools/check_pr53_pr56_integration.py
python -m unittest discover -s tools/tests -p test_pr102_source_preservation.py -v
```

The regression test copies only the source checker inputs to a temporary local
directory. It proves that the exact current source passes, then independently
mutates each of the five reviewed files and requires `parent source lost` for
that exact path. It also proves that an unrelated protected parent source and
schema changes remain blocked. No Moodle instance or user data is involved.

Any subsequent edit to these protected files must produce FAIL until its exact
new content is reviewed and recorded; the PR102 exception is not open-ended.

## Acceptance limits

Source preservation and negative-control PASS do not prove successful upload,
company isolation, real browser behaviour, or deployment readiness. Use the
current candidate CI and isolated Moodle evidence for those conclusions. No
old PR100 browser PASS is transferred automatically to the new package.
