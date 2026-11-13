# RC29 build2026110903 — selective integration of PR53 and PR56

Source candidate only; no merge, staging or production deployment approval.

- Retains learning0804, personal Moodle review queue, Role Intelligence,
  staffing/design fixes and tests from PR53 exact28a60d0.
- Retains communication, Corporate ERP/privacy and RC30 diagnostics/regressions
  from PR56 exact1ced6b8. Corporate remains2026110901/API1.26 unchanged.
- Resolves the two different BMS0902 lineages with unique0903. Chronological
  version-only guards are preserved; schema helpers/install.xml are unchanged.
- Both exact0902 sources are separate upgrade origins in the isolated matrix.
- Personal Moodle permissions and BMS company UUID/RBAC remain distinct; no new
  grants, membership migration, schema changes or rewriting of published data.

See docs/reconcile/PR53_PR56_CONFLICT_MAP.md for the pre-integration decisions.
Actual combined-tree acceptance is reported separately. Existing failures remain
HOLD; source preservation and a higher version do not authorize deployment over
the separately installed Corporate2613 feature tree.
