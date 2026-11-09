# BMS24 RC29 candidate 2026110601

Adds the personal Moodle competency review queue to the packaged 2026110504
baseline. The queue is scoped by native Moodle target-user permissions, not by
BMS company membership. Assignment access is checked again when opening a link.

The action is labelled Open related work. It does not claim an exact association
between a review request and a submission attempt. Repeated completion evidence,
later evidence, reopened attempts, multiple submissions and submissions modified
after evidence creation use the native competency-review fallback.

Moodle stores no durable request-to-attempt association. A manual request can
still reference an earlier first submission; same-second edits are not resolved
by timestamps. Exact historical binding remains an explicit follow-up debt.

Version checkpoint only: no schema, capability or business-data migration.
Release remains 1.1.0-rc29. Local independent verification is recorded outside
the install archive. Stage41 acceptance is pending availability of the server.
No production deployment or production readiness is asserted.
