# BMS Cloud — CLOUD-1a contracts and offline validator (v0.1.1, PROPOSED)

Scope per the CLOUD-1a v0.1 specification in issue #204 (comment 7236767050), built on
CLOUD-0 (#207, Codex PASS on `b030331`) and owner decisions OD-1/3/6/7/8/9.

**What this is:** the imported REG-002 v0.1 schema package (authority for the nine Registry
entities), a CLOUD-1a overlay and validation envelope, the Entitlements catalog, a mapping to
the existing Moodle projections, synthetic A/B fixtures and an offline validator with tests.
Standard-library Python 3.8+ only, no network. PyYAML is optional, used only by the YAML == JSON test.

**What this is not:** runtime, API, hosting, DDL/migration, enforcement, billing, provisioning,
DNS, real data or secrets. Nothing under `local/` changes. A passing validator proves
**contract consistency only**. It is **not** evidence of real restore (G3 stays HOLD and is
separate), Moodle behaviour, application ACL or production readiness.

## Layout

| Path | Content |
|---|---|
| `contracts/registry/v0.1/imported/` | REG-002 members, byte-exact (`.gitattributes -text`): logical model YAML + JSON, exchange schema, Mermaid ERD. The ZIP and the PNG are not committed |
| `contracts/registry/v0.1/registry_overlay.json` | Short names → package entities, CLOUD-1a vocabularies, derived rules, application definitions, handlers, invariants, clarifications C1–C9 |
| `contracts/registry/v0.1/exchange.schema.json` | Draft 2020-12 validation envelope: `registry` = a REG-002 exchange document validated by the **unmodified** imported schema; CLOUD-1a additions only in `extensions` and the envelope arrays |
| `contracts/registry/v0.1/reg002_reconciliation.json` | Import provenance and all 65 differences between the f30cbae transcription and the package, each with a resolution; extensions X01–X11 |
| `contracts/entitlements/v0.1/catalog.json` | Canonical `mgmt.*` / `limit.*` codes, bundles, dependencies, legacy aliases (`management.*`, `bms.*`; Hire = open) |
| `contracts/mapping/v0.1/projection_mapping.json` | Workspace → `local_kbacompany_cloudmap` via the primary WorkspaceCompanyLink, status/ERP/health maps, snapshot rules, ACL separation |
| `contracts/provenance/reg002_package_manifest.json` | ZIP and member SHA-256, verification record, import state IMPORTED |
| `fixtures/v0.1/{positive,negative}/` | Synthetic fixtures v0.1.1 (`base` + JSON-pointer `patch`, optional `catalog_patch`) |
| `tools/cloud_contracts.py` | Validator |
| `tests/test_cloud_contracts.py` | unittest suite |

## Run

```
python cloud/tools/cloud_contracts.py validate            # package + contracts + Moodle cross-check + all fixtures
python cloud/tools/cloud_contracts.py --json validate     # machine-readable
python cloud/tools/cloud_contracts.py check payload.json  # one envelope payload
python cloud/tools/cloud_contracts.py verify-reg002 --zip REG-002.zip --extract cloud/contracts/registry/v0.1/imported
python -m unittest discover -s cloud/tests -v             # BMS24_REG002_ZIP=<zip> also re-verifies the original
```

Exit codes: `0` valid, `1` contract violations, `2` bad invocation or unreadable input
(including unreadable Moodle sources or a missing imported member). Diagnostics are sorted and stable:
`ERROR <INVARIANT> scope=<contract:…|fixture:<id>|payload:<file>> path=<json-pointer> :: <message>`.

`validate` re-hashes the imported members against the manifest (`PKG_HASH_MISMATCH`), checks
that the package model and its exchange schema agree (`PKG_MODEL_DRIFT`), that the overlay maps
the nine package entities exactly once, and that the reconciliation pins the same hashes. It
reads three Moodle files read-only (`workspace_mapping_service.php`,
`entitlement_snapshot_service.php`, `bms_tariff_catalog_service.php`) to detect drift in the cloudmap
enums, the `mgmt.*` snapshot rule and the legacy tariff codes. The JSON Schema support is a fail-closed
subset that fully covers the imported schema. `format` (uuid, date-time, uri) is asserted (C9).

## REG-002 import (IMPORTED)

Package: Drive `1y0CAa4nHhnSRxMRJHSIpWACRdC8C2cX6`, 151666 bytes, SHA-256
`093fa293…a28f3`. Codex restored the original ZIP from the same Drive ID locally and verified it
on f30cbae. This branch re-ran `verify-reg002 --extract` on that ZIP: the ZIP and 4/4 selected
members PASS, and 4 members were extracted. The ZIP and the PNG are not in Git.

Package-internal consistency: YAML == JSON PASS. The model and the exchange schema agree on fields,
required flags, enums and patterns for all nine entities.

### Reconciliation with the f30cbae transcription

65 mechanical differences, 0 package files changed:

| Resolution | Count | Examples |
|---|---|---|
| aligned_to_package | 49 | `link_status` = active/closed; `actor_type` without operator; `product_bundle_code`; `workspace_company_links` / `entitlements` collections; `updated_at`, `created_at`, `adapter_version`, `last_synced_at` required; `slug` unique + pattern; one assignment per `entitlement_code` (C8) |
| moved_to_extension | 4 | subscription provenance (X01), assignment `source_type` (X02), job `request_hash` (X04), job `handler` (X05) |
| derived | 3 | isolation from `workspace_uuid` null/set; reactivation from an audited `cloud.workspace.reactivated`; manual grant reason from `AuditEvent.reason` |
| cloud1a_extension | 9 | `commercial_provider` vocabulary (X03); tenant-binding fields immutable (X07) |

Open questions for REG v0.2 remain clarifications C1 (ERP draft), C2 (isolated mgmt), C8 (assignment
granularity) and C9 (format assertion).

## Requirements matrix (spec → evidence)

| Requirement | Evidence |
|---|---|
| 9-entity REG-002 model; YAML and JSON one model | `imported/`; YAML==JSON test; `PKG_MODEL_DRIFT`; `MODEL_ENTITY_MAPPING` |
| Checksums before import, provenance kept | manifest; `verify-reg002`; `PKG_HASH_MISMATCH`; reconciliation `import` |
| Source conflicts → clarification table, no silent change | `reg002_reconciliation.json` (65 rows), overlay C1–C9 |
| Workspace → cloudmap via WorkspaceCompanyLink | mapping `workspace_to_cloudmap`; P01; N16, N28 |
| Entitlements → existing snapshot/resolver, `mgmt.*` only | mapping `snapshot`; P01; N18; non-mgmt snapshot test |
| Canonical `mgmt.*`/`limit.*`, transparent legacy mapping, Hire open | catalog `legacy_aliases`; `INV_LEGACY_MAPPING_MISSING` vs Moodle; N05 |
| Entitlements ≠ ACL | mapping `acl_separation` (declarative; no ACL tested) |
| Manual beta SubscriptionReference with audit + provenance | P01 (X01); N10, N27 |
| Hybrid shared + isolated; existing shared company keeps binding | P01 (A shared + legacy_compatibility, B isolated); N20 |
| Positive: independent A/B, Company UUID link, valid statuses | P01 |
| Positive: idempotent replay → one result | P02 |
| Positive: history kept on suspension | P03 |
| Positive: ERP needs link | P01 (B ERP active, linked); N12 |
| Positive: closed workspace job only after explicit reactivation | P04; N13 |
| Audit / handler checks at contract level | N17, N19, N22; N23, N26 |
| Negative: cross-tenant A→B | N01 (+ audit-scope test) |
| Negative: immutable workspace UUID change | N02 |
| Negative: dangling reference | N03 (+ dangling extension test) |
| Negative: invalid enum | N04 (REG-002 enum) |
| Negative: ambiguous legacy mapping | N05 |
| Negative: dependency / bundle cycle | N06 / N07 |
| Negative: conflicting idempotency key | N08 (+ job-level test), N09 |
| Negative: missing provenance | N10 |
| Negative: raw secret | N11, N30 (+ private-key test) |
| Extra negatives | N14 duplicate id, N15 hard delete, N21 unknown code, N24 dependency, N25 bundle scope, N29 duplicate slug |
| Stable diagnostics, exit codes 0/1/2 | `CliTests`, JSON-stability test |
| Versioned schema and fixtures | `v0.1` folders, `payload_version` / `fixture_version` 0.1.1, `catalog_version` |

## CI

Not wired. `.github/workflows/bms24-ci.yml` runs an explicit list of Python tests, so these
tests do not run in CI until a workflow change is agreed separately. Codex will put the steps up for agreement.
