# PR90 disposable acceptance

Test branch only; not a release and not a request to merge. It depends on PR96 head `38a2115b9b00692604b9b0520239bc07014d4944` (independent HOLD), and selectively imports only `task_center.css` from PR90 head `62a3e9cafd8252fffc6389af044bf469ff7e3853`. Main baseline is `f34ab6ff0d2f67a56972e99c23d096ed77a9873c`. No changes to Claude's files or rulesets.

The dedicated Actions workflow provisions a disposable runner and a pinned MariaDB or Percona service; no external environments, real people or backup credentials are used. Moodle is pinned at `b27f0816159a7555a0b3ad9ff2f8f8ef701a29fa`, PHP 8.1, Playwright 1.49.1. HTTP binds loopback, browser external requests are aborted, email disabled, no cron or integration configuration. This is not a claim of host-level egress firewall isolation.

Sequence: baseline fresh Moodle and synthetic A/B → code+DB+files snapshot → deterministic candidate install ZIP → upgrade/cache purge → menu/actor/write/browser smoke → full restore → full DB dump equality, code/file hashes and access checks → install same ZIP again → repeat smoke. Source checkout SHA and tree are recorded separately from the PR head: checkout on pull_request is the synthetic merge commit. The install ZIP hash and manifest are public; source archive is not substituted for it.

M01–M05 cover actual menu geometry, viewport widths 320/360/390/480/482/720/768/1280/1440, keyboard visible focus/Enter/Space, last-item hit testing and page overflow. M06 reuses actual task-tab keyboard/touch44px scenario. M07 compares scoped MA/EA/EB task menus and explicit foreign task refusal to baseline. M08 executes task-discussion writes, double-submit and invalid CSRF; it does not claim a complete task state-machine test. M09 checks owned/legacy allowed and forbidden routes. M10 independently observes page errors, console errors and HTTP5xx around browser producers. The only permitted console exception is an exact URL of an intentionally aborted external request, with ERR_FAILED evidence; arbitrary browser errors are not ignored.

`observe.py` is an independent acceptance wrapper. It does not repair or replace the remaining PR96 contract defect. Production acceptance still depends on independent approval of PR96 and all existing FULL/security/Corporate/release checks. A successful rehearsal alone does not authorise publication.

Artifacts: `rehearsal.json`, ZIP, manifest, SHA256SUMS, per-phase observations and screenshots. Never upload actors.private.json, config.php, SQL dumps, snapshot directories or private server logs. Each report applies only to its recorded tree; older PASS does not transfer to changed harnesses.

Run via this branch's pull-request event. The script refuses execution without GITHUB_ACTIONS=true, TEST_ROOT under RUNNER_TEMP and DBHOST=127.0.0.1. Database name bmsci_web is disposable only. Do not run this workflow against a shared/self-hosted production or staging runner.
