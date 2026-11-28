# BMS Cloud — CLOUD-1a contracts and offline validator (v0.1, PROPOSED)

Scope per the CLOUD-1a v0.1 specification in issue #204 (comment 7236767050), built on
CLOUD-0 (#207, Codex PASS on `b030331`) and owner decisions OD-1/3/6/7/8/9.

**What this is:** versioned Registry and Entitlements contracts, a JSON Schema Draft 2020-12
exchange contract, a mapping to the existing Moodle projections, synthetic A/B fixtures and an
offline validator with tests. Standard-library Python 3.8+ only, no network.

**What this is not:** runtime, API, hosting, DDL/migration, enforcement, billing, provisioning,
DNS, real data or secrets. Nothing under `local/` changes. A passing validator proves
**contract consistency only**. It is **not** evidence of real restore (G3 stays HOLD and is
separate), Moodle behaviour, application ACL or production readiness.

## Layout

| Path | Content |
|---|---|
| `contracts/registry/v0.1/registry_mvp.json` | REG-002 logical model: 9 entities (CloudCustomer → AuditEvent), enums with provenance, application definitions, handlers, invariants, clarifications C1–C7 |
| `contracts/registry/v0.1/exchange.schema.json` | Draft 2020-12 exchange payload; enums tagged `x-bms-enum` (must equal the registry) and `x-moodle-enum` (must equal Moodle) |
| `contracts/entitlements/v0.1/catalog.json` | Canonical `mgmt.*` / `limit.*` codes, bundles, dependencies, legacy aliases (`management.*`, `bms.*`; Hire = open) |
| `contracts/mapping/v0.1/projection_mapping.json` | Workspace → `local_kbacompany_cloudmap` via the primary WorkspaceCompanyLink, status/ERP/health maps, snapshot rules, ACL separation |
| `contracts/provenance/reg002_package_manifest.json` | REG-002 ZIP and selected-member SHA-256 with provenance; import state HOLD |
| `fixtures/v0.1/{positive,negative}/` | Synthetic fixtures (`base` + JSON-pointer `patch`, optional `catalog_patch`) |
| `tools/cloud_contracts.py` | Validator |
| `tests/test_cloud_contracts.py` | unittest suite |

## Run

```
python cloud/tools/cloud_contracts.py validate            # contracts + Moodle cross-check + all fixtures
python cloud/tools/cloud_contracts.py --json validate     # machine-readable
python cloud/tools/cloud_contracts.py check payload.json  # one exchange payload
python cloud/tools/cloud_contracts.py verify-reg002 --zip REG-002.zip --extract cloud/contracts/registry/v0.1/imported
python -m unittest discover -s cloud/tests -v
```

Exit codes: `0` valid, `1` contract violations, `2` bad invocation or unreadable input
(including unreadable Moodle sources). Diagnostics are sorted and stable:
`ERROR <INVARIANT> scope=<contract:…|fixture:<id>|payload:<file>> path=<json-pointer> :: <message>`.

The validator reads three Moodle files read-only (`workspace_mapping_service.php`,
`entitlement_snapshot_service.php`, `bms_tariff_catalog_service.php`) to detect drift between
these contracts and the accepted cloudmap enums, the `mgmt.*` snapshot rule and the legacy
tariff codes. The JSON Schema support is a fail-closed subset: any keyword it does not
implement is a contract error.

## REG-002 import (HOLD)

Package: Drive `1y0CAa4nHhnSRxMRJHSIpWACRdC8C2cX6`, 151666 bytes, SHA-256
`093fa293…a28f3`, verified by Codex in #204 (posted from the owner account). Selected member
hashes are pinned in the manifest; the PNG is excluded.

**Blocker:** the package exists in Drive only as a ZIP. In this session the Drive connector
returns file bytes only inside model context and cannot write them to disk, and there is no
authenticated shell download path. The members were therefore **not** extracted or byte-verified
here (`members_verified: NOT_RUN`). `registry_mvp.json` is a clearly labelled transcription from
the REG-002/REG-001 text, not the imported YAML/JSON.
**Unblock:** place the ZIP locally and run `verify-reg002 --zip … --extract …`. It extracts
only when the ZIP and every selected member hash match. Then diff the imported model against
`registry_mvp.json` and record any conflict as a clarification; never change the contract silently.

## Requirements matrix (spec → evidence)

| Requirement | Evidence |
|---|---|
| 9-entity REG-002 model, one logical model | `registry_mvp.json`; `MODEL_ENTITY_*`, `MODEL_ENUM_DRIFT` checks |
| Provenance per file / enum | `provenance` blocks, `enum_provenance`, manifest |
| Source conflicts → clarification table | `clarifications` C1–C7, mapping `*_notes` |
| Workspace → cloudmap via WorkspaceCompanyLink | mapping `workspace_to_cloudmap`; P01; N16 |
| Entitlements → existing snapshot/resolver, `mgmt.*` only | mapping `snapshot`; P01; N18; snapshot non-mgmt test |
| Canonical `mgmt.*`/`limit.*`, legacy mapping transparent, Hire open | catalog `legacy_aliases`; `INV_LEGACY_MAPPING_MISSING` vs Moodle; N05 |
| Entitlements ≠ ACL | mapping `acl_separation` (declarative; no ACL tested) |
| Manual beta SubscriptionReference with audit + provenance | P01; N10 |
| Hybrid shared + isolated, existing shared company keeps binding | P01 (A shared + legacy_compatibility, B isolated); N20 |
| Positive: independent A/B, Company UUID link, valid statuses | P01 |
| Positive: idempotent replay → one result | P02 |
| Positive: history kept on suspension | P03 |
| Positive: ERP needs link | P01 (B ERP active, linked); N12 |
| Positive: closed workspace job only after explicit reactivation | P04; N13 |
| Audit / handler checks at contract level | `INV_AUDIT_MISSING` N17, N19; `INV_UNKNOWN_HANDLER` N23 |
| Negative: cross-tenant A→B | N01 (+ audit-scope test) |
| Negative: immutable workspace UUID change | N02 |
| Negative: dangling reference | N03 |
| Negative: invalid enum | N04 |
| Negative: ambiguous legacy mapping | N05 |
| Negative: dependency / bundle cycle | N06 / N07 |
| Negative: conflicting idempotency key | N08 (+ job-level test), N09 |
| Negative: missing provenance | N10 |
| Negative: raw secret | N11 (+ private-key test, secretref pattern test) |
| Extra negatives | N14 duplicate id, N15 hard delete, N20 isolation, N21 unknown code, N22 grant reason, N24 dependency |
| Stable diagnostics, exit codes 0/1/2 | `CliTests`, JSON-stability test |
| Versioned schema and fixtures | `v0.1` folders, `payload_version`, `fixture_version`, `catalog_version` |

## CI

Not wired. `.github/workflows/bms24-ci.yml` runs an explicit list of Python tests, so these
tests do not run in CI until a workflow change is agreed separately (per the CLOUD-1a GO).
