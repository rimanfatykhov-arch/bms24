# Data Model v0.34.1

No new tables or fields.

Read sources:

- `local_kbacorp_company`;
- `local_kbacorp_econ_ind`;
- `local_kbacorp_econ_val`;
- `local_kbacorp_impl_audit` for optional audit context.

Logical keys:

- indicator: stable `code`;
- periodic value: company + indicator + `periodkey` + scenario;
- audit snapshot: values with the same `auditid` and scenario `actual`.

Computed fields such as delta, percent, direction state, chart labels and chart values are not persisted.
