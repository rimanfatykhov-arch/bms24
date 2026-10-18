# Release Notes — v5.0.36.1.4

The v5.0.36.1.3 diagnostic itself was too strict about the production database schema.
This hotfix makes diagnostics schema-tolerant and read-only so we can inspect the real
catalog binding problem without modifying catalog data.
