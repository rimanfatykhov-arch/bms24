# RC33 PR-3 — fake MAX adapter and D4 acceptance harness

Status: implementation candidate, **acceptance HOLD** until the FULL tier (MariaDB 10.11.19 and Percona 8.0.46) has run
this harness and independent QA/security review is done. Executor: Claude. Base: PR-2 `feature/max-inbound-server`
622707e (#179) on PR-1 (#178). Decision: owner D1–D4 of 24.11.2026 (all «А»), ADR-0012. No plugin, schema, version,
capability, lang or UI change.

## Change

- `tools/max_fake_adapter/fake_max_adapter.py`, stdlib only. It implements contract §3, §8 and §9 step 2:
  - binds to loopback only and calls BMS only on a plain loopback URL; no MAX network, token or files;
  - the webhook secret is one exact header compared in constant time;
  - 64 KiB limit and strict JSON: no duplicate keys, floats, NaN or excessive depth;
  - provider IDs are exact int64 values, and no security flag gets a default;
  - unbound chats are recorded as rejected without body and never sent.
- Durable inbox in SQLite WAL:
  - one row per source event, and repeated deliveries only increase a counter;
  - a webhook gets 200 only after the row is durable;
  - a crashed worker's lease expires, and terminal rows drop the body.
- Worker calls the real REST functions; the token goes in the POST body, never in the URL or logs. Moodle exception
  envelopes with HTTP 200 map to these states:
  - retryable → retry with backoff;
  - unauthorised → connection paused and the event kept;
  - scope or payload refusal → rejected;
  - idempotency conflict → dead letter.
- Errors 429/5xx and network failures back off with jitter from 1 s to 5 min, honour Retry-After, and go to dead
  letter after 12 attempts.
- `tools/tests/test_max_fake_adapter.py`: 12 tests against an in-process fake BMS, covering the adapter side of
  N01, N02/N25, N03, N09, N10, N20, N24 and N32.
- FULL tier (`tools/ci/browser_acceptance.py`): a new step runs `tools/ci/browser/max_inbound.py` with
  `ci_inbound.php` on the disposable Moodle (PHP built-in server with 4 workers, MariaDB and Percona). It sets up:
  - REST, the inbound service and the inbound switch, on that throw-away site only;
  - for companies A and B: principals with tokens, two bindings and sender mappings.
- It then records:
  - **N09**: 20 parallel identical REST deliveries;
  - **N20/N02/N04/N11**: malformed, duplicate key, foreign company, changed content, bad token, switch off;
  - **adapter end to end**:
    - 20 identical webhooks;
    - wrong secret;
    - bot, attachment, reply, forward, channel and unbound chat;
    - remove before create;
    - edit after create;
    - kill switch while waiting;
  - **N21**: sender revocation during intake;
  - **N28**: ADR-0006 membership revocation during intake;
  - **N28 object ACL** (COORD-D05, owner option A in #205): posts into the discussion of a task, a Battle Plan and a
    meeting of company A while its writer loses the right: `task_service::set_observer(0)` for the task observer,
    `battle_plan_service::set_observer(0)` for the plan observer, and ADR-0006 closure for the meeting participant
    (there is no participant removal API). Each post is its own PHP CLI process. Task and plan posts alternate the
    object path (`post_discussion_message`, `battle_plan_discussion_service::post`) and the generic
    `communication_service::send_message`; meeting posts use the generic path.
- In each race, the revocation runs in its own PHP process while 15 imports or posts run in parallel, with 5 before
  and 5 after.
- `contract.inbound_checks` makes the nine verdicts from DB state that a separate PHP process reads after each step.
  The checks are required in `required_check_names()`, and the merge gate uses that list.
  - The race invariant: no import publishes a message by the revoked author with an id higher than the one read after
    the revocation committed.
  - Answers and receipts must agree.
  - Every receipt after the revocation is a refusal.
  - N28 object ACL: no post by the revoked writer has an id above the one read after the revocation committed. Every
    answer is a commit or one of the exact refusal codes of that path. The committed messages equal the `ok`
    answers. The write right is present before and absent after. A post by a remaining writer afterwards does not
    notify the revoked writer. Task and plan races must exercise both write paths.
  - `test_contract.py` covers a consistent observation and 43 single mutations (20 of them N28 object ACL).

## Not covered or still open

- The FULL tier (`ci-tiers.yml`) runs only for pull requests into `main`. The stacked PRs #178, #179 and this one get
  only `bms24-ci`. The D4 evidence (PHPUnit `max_inbound_service_test` plus this harness on MariaDB and Percona)
  appears only when the stack is targeted at `main`, which is an owner decision. Until then D4 is **NOT RUN**.
- The concurrency limit is the 4 PHP built-in server workers and the CLI revoker, not a production web server.
- N28 object ACL (PR-1 after COORD-D05) is serialized for the narrowing writers only. The residuals are listed in
  `docs/reconcile/RC33_PR1_COMMUNICATION_WRITE_GATE.md`. The D4 races cover one writer per object type: task observer,
  plan observer and meeting participant closure. Other gated writers (`save`, `transition`, `delete_task`, `review`,
  coverage, roles) are covered by the static guard and PHPUnit, not by a parallel race.
- N17/N18 (files), N23 (outbox consumer) and live MAX (D5–D8) remain out of scope.
