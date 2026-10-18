# Data Model v0.34.0

No new tables or fields.

Read sources:

- `local_kbacorp_impl_audit`;
- `local_kbacorp_impl_item`;
- `local_kbacorp_impl_task`;
- `local_kbacorp_impl_evid` when installed;
- `local_kbacorp_econ_ind`;
- `local_kbacorp_econ_val`.

Historical criteria are joined logically by `criterioncode`. Economic values are joined by stable `indicatorcode` after selecting the latest linked actual value per audit.
