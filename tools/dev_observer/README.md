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
