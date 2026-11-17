# Daily reports R2 — manager review, rating, return and rework

Decision: [ADR-0009](https://github.com/rimanfatykhov-arch/bms24/pull/115), stage R2 (owner decisions В1–В7, В10, В12).
Base: R1 `feature/daily-reports-r1-store` (PR #118) on R0 (PR #116). Not deployed, not merged.

## Behaviour

| Rule | Implementation |
|---|---|
| Who decides | Only the reviewer stored on the **current** version (`dreportver.reviewerid`). The author never decides; a corrupted self assignment is refused on the server too. |
| Decision | Bound to one version: `accepted` with rating `positive` / `negative` / `none` (negative needs a comment), or `returned` with a mandatory reason. |
| Idempotency | `(versionuuid, requestkey)` is unique; a repeated request returns the first decision without new rows or notifications. |
| Stale version | A decision on a version that is no longer current (rework, correction) is refused (`dailyreports_staleversion`). |
| Rating change / revoked acceptance | New append-only `dreportrev` row with `supersedesuuid` and a mandatory reason; the earlier row stays. |
| Rework after return | `start_revision()` copies the returned text into version N+1 (draft); the old version, its reason and decision stay. |
| Correction after acceptance (В4) | `start_revision()` with a mandatory explanation (`correctionnote`); the old rating stays on the old version; the new version starts without a rating. |
| Reassignment (В2) | Company administrator (`permission_service::can_manage_people()` in this company) sees "Without reviewer" and reassigns a pending report to another eligible member; journaled as `reviewer_reassigned`. |
| Read access | Reviewer of any version of the report (В6: earlier versions stay with their stored reviewer); administrator read-only. Authors use their own view. Unknown and foreign reports give the same answer. |
| Tasks | A decision never accepts, closes or changes tasks. |
| Notifications | Canonical Communication Center notification (deterministic key per event) plus one Moodle message. Kinds `dailyreport_submitted` (to the reviewer, on submit and reassignment) and `dailyreport_decision` (to the author). |

## Changes outside the new files

- `communication_service::NOTIFICATION_KINDS` gains `dailyreport_submitted` and `dailyreport_decision` only (exact
  hash recorded in `daily-reports-r2-source-preservation.json`).
- `db/messages.php`: two message providers with capability `local/kbacompany:viewowncompany`.
- Version 2026111600 → **2026111601**: version identity step only, so Moodle registers the providers. No DDL.
- Release identity gates updated with exact values (same file set as R1, see `DAILY_REPORTS_R1_SCHEMA.md`).

## Not in R2

Attachments (separate slice), Communication Center discussion thread for a report (R3, after ADR-0008), employee ×
day matrix view, reminders and "not submitted" schedule (stage 2, В10), batch decisions (excluded in the design).

## Rollback

Same as R1: switch `dailyreports_enabled` off (read-only summary, data kept), forward fix, or database restore by a
separate owner decision. No code-only downgrade below the installed version.
