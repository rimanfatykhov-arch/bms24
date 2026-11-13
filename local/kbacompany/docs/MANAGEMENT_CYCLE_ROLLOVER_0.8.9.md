# BMS24 Management Cycle — Next Period & Rollover 0.8.9

## Purpose
Continue management cadence into the next period without copying canonical execution objects or rewriting history.

## Rules
- One canonical Management Cycle engine remains in `local_kbacompany_mcycle`.
- Next-period creation is explicit and produces a new **draft** cycle.
- Open canonical Tasks are carried as references in `mcycle.statepayload.rollover.carrytaskuuids`.
- Source Battle Plans are preserved as historical references; no Battle Plan is cloned.
- Original Task due dates, statuses, results, revisions and history are not modified by rollover.
- Users deliberately choose which unfinished Tasks are relevant to the next period.
- The existing Task/Battle Plan UI remains authoritative for actual inclusion of a Task into a new weekly Battle Plan.
- Calendar cascade is explicit: **Year → Quarter → Month → Week**.
- A child calendar cycle must fit completely inside its parent period.
- Project and Advisor 4×90 cycles remain non-calendar wrappers and are not forced into that hierarchy.
- All reads/writes are company-scoped.

## No new storage
0.8.9 adds no database table. Rollover metadata is stored inside the existing cycle `statepayload` JSON.
