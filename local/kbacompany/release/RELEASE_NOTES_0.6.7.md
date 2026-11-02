# BMS v0.6.7 — Entitlement Snapshot Client / Cloud Contract

Build: 2026092128

- Added validated local entitlement snapshot cache for `mgmt.*`.
- Added last-known-good TTL/degraded behavior with a 24-hour stale continuity window.
- Added restricted, disabled-by-default BMS Cloud API v1 for snapshot contract/apply/status.
- Added dedicated `local/kbacompany:cloudsync` capability with no default role grants.
- `entitlement_service` now resolves real Cloud snapshots only in explicit `cloud_snapshot` mode.
- Applying a snapshot does not switch existing companies out of `legacy_compatibility`.
- Platform diagnostics now expose snapshot revision/state/age/validity metadata.

No production deployment, PHPUnit, live Cloud callback, browser/E2E or staging upgrade was executed while building this package.
