# BMS 0.7.4 — 360 Assessment

## Purpose
Adds company-scoped multi-rater feedback for published position competency requirements.

## Boundary
Individual 360 responses never write employee competency facts. A closed cycle produces an aggregate. The aggregate is transferred to a draft Assessment Engine session using method `feedback_360`. Only the existing Assessment Engine approval step may project decided levels to the Employee Competency Profile.

## Lifecycle
Draft → Collecting responses → Closed → Transferred to Assessment.

## Respondent groups
Self, manager, peer, direct report, other.

## Privacy
Respondent assignments and individual scores are stored for access control/privacy handling. Subject-facing aggregate does not expose respondent identity or individual scores. Management setup shows completion state so the cycle can be administered.
