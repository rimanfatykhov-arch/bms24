# RC29 candidate 2026110802 — reviewer route

The review queue keeps its place while the reviewer opens the related assignment or native competency review in a new tab. Refresh results shows the published assignment grade separately from competency review status. This display does not publish grades or confirm competencies.

Published grades require the existing restrictive related-work resolution and native permissions, plus student grade visibility, released workflow, visible gradebook items and grades, and an up-to-date final grade. Ambiguous or repeated evidence keeps the native competency review fallback. Permissions are checked again when opening a work link.

The employee learning card explains that the assignment link opens teacher feedback. Course progress, assignment grade and competency confirmation remain separate.

No schema migration; version savepoint only. Release remains 1.1.0-rc29. Stage41 acceptance and test results are recorded in the external release report. Production deployment is not included. Back up the test database and plugin before installation; rollback restores them together.
