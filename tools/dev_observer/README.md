# BMS Development Observer v1

Run from the repository root with Python 3.10 or later. Provide a new output directory.

```console
python -B tools/dev_observer/observer.py --fixture tools/dev_observer/fixture.json --as-of 2026-11-28T06:00:01Z --out bdo-example
python -B -m unittest discover -s tools/dev_observer -v
```

The example marks #10 BLOCKED (unassigned), #11 HOLD, #12 BLOCKED (prerequisite), #13 READY, #14 DOC-SYNC HOLD (drift), #15 DOC-SYNC HOLD (Drive unavailable) and #17 READY (verified synthetic release receipt and smoke). These are synthetic records.

For an optional live read, provision a fine-grained BMS24-only token with read-only Metadata, Contents, Pull requests, Issues and Actions permissions. Pass it through the process environment as `BDO_GITHUB_READ_TOKEN`. Use a separate verified evidence file when available.

```console
python -B tools/dev_observer/observer.py --github --evidence checked-evidence.json --out bdo-live
```

The collector uses the fixed BMS24 REST origin, implements GET only, and rejects redirects. It returns a partial snapshot when collection or consistency checks fail. It records artifact IDs and links without fetching bytes, and records review/comment references without exporting discussion text or personal identifiers. Live data without separately verified inputs yields conservative findings. A missing release or docs source requires no credentials for those systems.

## Contract v1

A fixture is a JSON object with `schema_version: 1`, `snapshot` and optional `evidence`; see [fixture.json](fixture.json) for a full positive PR and negative issue examples. Live collection constructs the same snapshot data. The snapshot fields are UTC `collected_at`, exact `main`, boolean `complete`, `errors` with route and reason, and `tasks`. Task records use repository-global `number`, `kind` (pr/issue), `executor` from registry #198, optional explicit `registry_status`, `state`, and PR `head`, `tree`, `base`, `draft`, `merged`, `mergeable`, `comments`, `reviews` and `runs`. Each run has ID, attempt, workflow path, exact PR/head/base association when present, outcome, jobs and artifact metadata. A live run without exact association is visible metadata and cannot satisfy the gate.

An optional standalone `--evidence` file has `schema_version: 1` and `tasks` indexed by repository-global number. This is a **local handoff from a verifier**. Only use controlled inputs whose referenced evidence has been verified independently. GitHub titles, labels and prose never populate these fields. Observer checks identity, freshness and references. Source authenticity, reviewer appointment and cross-system receipt verification are the verifier's responsibility.

| Evidence | Required contents |
| --- | --- |
| Task | Exact head/tree/base for a PR, current main as head for an issue, UTC checked_at, dependencies (explicit list, empty for independent tasks), requires_docs (boolean). Only set completed upon verified completion. Set hold only for a recorded hold. |
| CI | gate contains candidate identity, run/attempt, FAST/STANDARD/FULL profile and decision produced by the existing merge gate. Observer checks observable required jobs, exact run identity and latest attempt. The canonical decision covers stamped report bytes, browser contracts and negative controls. |
| Review | review contains candidate identity, exact review or comment ID, reviewer role, appointed and independent booleans, and explicit PASS/FAIL. Verify the source and the reviewer's assignment before preparing the input. |
| Release | release contains package identity, package run/attempt and artifact ID, ZIP and manifest digests, manifest identity and matching ZIP/baseline, exact owner approval, and release_checks for mandatory candidate-specific checks. Record PUBLISHED with a verified server receipt tied to the same candidate/package; record VERIFIED only after smoke. |
| Docs | docs contains document_ref (stable numeric source reference supplied by verifier), drive_digest and github_digest for the two known revisions. Use canonicalized content digests under the same procedure. Missing inputs are NOT RUN/DOC-SYNC HOLD. |

A complete, fresh snapshot can still contain blockers. Snapshot and evidence freshness use 24 hours, and future timestamps are invalid. A READY task cannot satisfy another task's dependency until completion is confirmed. Registry lines that cannot be mapped unambiguously are blocked.

The five output files are `snapshot.json` (versioned observations and Observer findings), `development-report.md`, `blockers.json`, `ready-tasks.json` and `owner-decisions.md`. The JSON task reports carry version, main, timestamp and freshness. Findings contain sources, candidate identity, executor, status, reason and one recommendation. Commit IDs for issue findings identify the observed main baseline. Job conclusions, verified reviewer verdicts and Observer classifications occupy different fields.

Exit status **0** means the report was produced from a complete, fresh snapshot. **1** means reports were produced with partial or stale source data. **2** means invalid input, output failure, missing live credentials or CLI usage error. These codes describe collection and reporting. They cannot authorize a release.

## Validation boundary

D01/D02/D07/D08/D17/D18 run against offline fixtures and mocked HTTP. Live GitHub read and live Drive comparison require authorized sources. TEST, staging, production, automatic orchestration and BDO-2+ scenarios are outside this implementation. A next proposal can standardize machine-readable registry and attestation inputs before adding more integrations.

## BDO-2 Scheduler dry-run

Run manually from the repository root against local inputs:

```console
python -B tools/dev_observer/scheduler.py --snapshot /local/snapshot.json --queue /local/verified-queue.json --as-of 2026-11-28T12:00:00Z --out /local/new-plan
python -B -m unittest discover -s tools/dev_observer
python -B -O -m unittest discover -s tools/dev_observer
```

The queue has `schema_version: 1`, `main` (the currently verified main SHA), `registry_ref` (a repository #198 issue or comment URL), `priorities_ref` (#205 issue or comment URL), and `tasks`. A task has `number`, `executor`, exact `head`, `tree`, and `base`, numeric `priority` (0 is highest), `dependencies` (task numbers), explicit `hold` (boolean) and `action`. An issue uses the snapshot main for `head` and `null` for `tree` and `base`. Supported actions are `continue_work` (also supply `branch`), `independent_review`, and `owner_decision`. To avoid simultaneous writes, recommendations with a shared branch are blocked. Use a queue prepared from verified assignments and priorities. GitHub prose or a PR label cannot supply an assignment or authority.

For `independent_review`, supply the independently appointed `reviewer`; an author is not eligible to review their own work. An open PR waiting solely for review can receive a read-only review recommendation when BDO-1 has current CI `PASS` and review `NOT RUN`. A recorded HOLD or a documentation HOLD still blocks it. The output preserves BDO-1's reason separately from the scheduler's decision.

The output directory contains `plan.json` and `summary.md`. The same inputs and evaluation time produce the same bytes. A prerequisite must have its own matching task record and a BDO-1 `COMPLETE` finding with reason `completion_confirmed`. A stale, incomplete or inconsistent snapshot produces diagnostics with no recommendations. Exit 0 means the input was usable, even if some tasks are blocked. Exit 1 means diagnostic plan only. Exit 2 means invalid input or a local write failure. The CLI never executes the plan.

## BDO-3 synthetic pilot

`runner.py` admits one fixed synthetic task, `bdo3-synthetic-sum-v1`. It recomputes the Scheduler plan from the approved snapshot and queue, checks current eligibility and rechecks admission immediately before launch. Default mode validates and prints a summary without creating a journal or launching a session.

```console
python -B tools/dev_observer/runner.py --approval /pilot/go.json --approval-sha256 <verified-digest> --plan /pilot/plan.json --snapshot /pilot/snapshot.json --queue /pilot/queue.json --session /pilot/session.json
```

Use a private directory under a dedicated local account or VM, with a clean standalone clone at the approved SHA and no Git remotes. Keep its parent free of project settings. Use a separate Codex profile containing only dedicated model authentication, with only authorized model connectivity. Files in the approval directory and its journal are trusted operator inputs and must be protected against other writers. Establish current occupancy of the exact workspace before issuing the GO. The local session proof records `workspace`, `observed_at`, `state: "vacant"` and `session_id: null`. Missing, stale or busy evidence blocks execution. The pilot does not attach to existing sessions or discover arbitrary Desktop sessions.

The operator pins the trusted approval digest independently. Approval schema v1 requires `decision: "GO"`, `approval_id`, `repository` (the BMS24 GitHub URL), `number` (synthetic issue ID), `actor: "Codex"`, `adapter: "codex-cli"`, `card: "bdo3-synthetic-sum-v1"`, `base_sha`, `not_before`, `expires_at`, `result: "result.json"`, and SHA256 fields `plan_sha256`, `snapshot_sha256`, `queue_sha256`, `session_sha256`. It also pins the absolute paths `workspace`, `codex_home`, `codex_cli`, and `codex_cli_sha256`. The clone and the dedicated profile are distinct children of the approval directory. The browser or repository text cannot supply or widen the GO.

Add `--execute` only for an actual separately authorized pilot. The bounded Codex CLI session uses a separate ephemeral profile and scratch directory, read-only tool sandbox, no shell, hooks, web search, apps or delegation, and a fixed structured response. The runner writes the verified `result.json` and `receipt.json` outside the checkout. It stores only structured status and reason codes from adapter events, with no raw transcript capture. Local tests mock the CLI protocol and require no model access.

`pilot.sqlite3` records transitions and the idempotency key. A workspace lock prevents launch and reconciliation from acting concurrently. An identical request returns the existing state or receipt. After an interruption use `--reconcile` with the original approval and input files. It validates a saved terminal outcome if present. Missing evidence yields `RECOVERY_REQUIRED`, retaining the launch reservation. Confirm the original process or session through operator evidence before deciding how to clear an unresolved reservation. A restart never resumes or launches a replacement agent automatically.
