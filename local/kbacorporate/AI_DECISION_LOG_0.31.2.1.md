# AI DECISION LOG — v0.31.2.1

## Decision

Treat `BMS-Dxx` competencies as grouping/domain nodes only. They are not valid terminal requirements for a business role, GAP calculation, course recommendation, or Moodle Learning Plan membership.

## Rationale

The pilot matrix exposed `BMS-D00` and `BMS-D01` as role requirements. Courses are mapped to actionable `BMS-Cxx-xx` competencies, so domain requirements produce no meaningful course coverage and distort GAP planning.

## Migration strategy

No automatic data rewrite during upgrade. An authorised manager receives a preview and an explicit action that expands each domain node into its direct child competencies. Existing concrete requirements are preserved. Domain nodes without children are retained and reported.

## Rejected alternative

Automatically expanding domains during plugin upgrade was rejected because it is a semantic data migration and could unexpectedly change a production role profile.
