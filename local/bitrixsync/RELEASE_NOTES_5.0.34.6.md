# Release Notes — v5.0.34.6

This is a control-center initialization repair only.

The previous v5.0.34.5 page attempted to call `role_schema_diagnostic()`
through an uninitialized `$schemaservice` variable.
