# AI DECISION LOG — v0.31.4 Course Coverage Integrity

## Decision 1 — keep Moodle core authoritative

Course-to-competency coverage continues to use the standard Moodle table/API. No parallel KBA relation table is introduced.

## Decision 2 — diagnostic first, no destructive repair

The integrity page reports duplicates and out-of-catalogue mappings but does not delete them. A Moodle course-to-competency relation is global and may be used by another company context. Automatic repair would therefore be unsafe.

## Decision 3 — mandatory coverage determines Learning Plan readiness

A role can proceed to an employee GAP/Learning Plan workflow only when every mandatory competency has at least one active visible course in the selected company. Supporting competencies may remain uncovered, but the status is shown as partial.

## Decision 4 — distinguish course catalogue quality from role coverage

The page separately reports active company courses that have no competency relations. Such courses do not necessarily block the selected role, but they indicate incomplete learning-catalogue metadata.

## Decision 5 — out-of-scope mappings do not count

Mappings to hidden courses or inactive assignments of the selected company are visible as anomalies but are not counted as active coverage. Courses assigned only to other companies are ignored because the Moodle relation is global and legitimate in another tenant context.

## Technical debt

A later controlled maintenance release may add backup-gated duplicate cleanup and explicit review/removal of out-of-scope mappings.
