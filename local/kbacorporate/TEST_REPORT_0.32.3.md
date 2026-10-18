# TEST REPORT — KBA Corporate Dashboard v0.32.3

## Status

**Static release gate: PASS.**  
**Pilot Moodle runtime tests: NOT YET EXECUTED.**  
Release classification: BETA / prepared for pilot validation, not Stable.

## Environment used for static checks

- Component: `local_kbacorporate`
- Build: `2026090809`
- Base: `0.32.2.1`
- Target minimum: Moodle 4.0 / PHP compatible source

## Static checks performed

| Test | Result |
|---|---|
| PHP syntax check for all plugin PHP files | PASS — 226/226 |
| `db/install.xml` XML parse | PASS |
| install.xml version | PASS — 2026090809 |
| `version.php` build/release | PASS |
| upgrade savepoint | PASS — 2026090809 |
| RU/EN key parity | PASS — 2563/2563 |
| Duplicate RU/EN keys | PASS — 0 |
| Literal local `get_string()` references | PASS — no missing keys |
| Additive/non-destructive migration review | PASS |
| ZIP root and integrity | PASS — one root `kbacorporate/`, 645 entries, 226 PHP files |
| SHA-256 | PASS — companion checksum generated after final packaging |

## Automated test source added

`tests/individual_development_plan_test.php` covers:

- pending-review as a first-class progress state;
- plan objective, mentor and review date;
- action goal, practice, evidence criteria and checkpoint;
- target-date protection against earlier action deadlines;
- plan agreement;
- evidence submission;
- self-review rejection;
- evidence approval;
- immutable history events;
- absence of automatic competency grading.

The PHPUnit file passed PHP syntax validation. Execution inside the configured Moodle PHPUnit environment is still required.

## Required pilot regression cases

### T-0323-01 Upgrade

Precondition: copy of production database on v0.32.2.1.  
Steps: install ZIP, run Site administration → Notifications.  
Expected: upgrade completes; fields, indexes and `local_kbacorp_devlog` exist; no existing plan/action is deleted.

### T-0323-02 Existing-data backfill

Expected: existing plan `reviewdate = targetdate`; existing actions remain present; required state is inferred where possible and otherwise remains mandatory.

### T-0323-03 Draft plan management

Expected: objective, plan mentor, target date and review date save; target date earlier than any action deadline is rejected.

### T-0323-04 Draft action management

Expected: goal is required; practical task, evidence criteria, action mentor, checkpoint and mandatory flag save; action deadline stays within plan dates.

### T-0323-05 Agreement and start

Expected: manager submits plan, employee accepts it, action moves from planned to in progress.

### T-0323-06 Course completion without evidence requirement

Expected: existing automatic completion behaviour remains available and competency level is not changed.

### T-0323-07 Course completion with evidence requirement

Expected: course completion does not complete the action; action remains/invokes in-progress state and asks for practical evidence.

### T-0323-08 Evidence submission and separation of duties

Expected: employee submits URL/comment; action becomes «Ожидает проверки»; submitter cannot approve or reject the same evidence.

### T-0323-09 Evidence approval/rejection

Expected: another authorised manager/mentor may approve; rejection requires a comment and returns action to work; approval completes action without grading the competency.

### T-0323-10 Checkpoint

Expected: checkpoint date must be between plan start and action deadline; comment is required; history event is recorded.

### T-0323-11 History

Expected: plan/action/status/evidence/checkpoint events appear newest first; events before 0.32.3 are not fabricated.

### T-0323-12 Mentor access lifecycle

Expected: assigned active company mentor has intended access; removing mentor from company revokes mentor eligibility and reminders.

### T-0323-13 Reminder isolation

Expected: plan mentor and managers receive plan-wide due items; action-only mentor receives only assigned actions.

### T-0323-14 Privacy and deletion

Expected: export contains new fields/history; subject deletion removes plans/actions/history; actor deletion anonymises actor IDs; company deletion removes history before plans.

### T-0323-15 Moodle integration regression

Expected: linked Moodle Learning Plan remains accessible and contains the same competency set; no automatic course enrolment or competency grading occurs.

## Exit criteria for Stable

- all applicable pilot cases PASS;
- PHPUnit suite executed in Moodle environment;
- database backup/restore rehearsal completed;
- no cross-company data exposure;
- TEST_REPORT updated with actual results, environment and tester.
