# BMS Management Public API v1 — v0.6.5

## Purpose

The Public API is the supported integration boundary between BMS Management in Moodle and future external BMS24 platform components such as BMS Cloud, Integration Hub and Odoo ERP adapters.

External consumers must not read Moodle or `local_kbacompany` tables directly.

## Moodle service

- Service: `BMS Management Public API v1`
- Shortname: `local_kbacompany_public_v1`
- Default: disabled
- User policy: restricted users only
- Foundation transport: standard Moodle external functions / web-service token
- v1 is read-only

## Functions

- `local_kbacompany_public_contract`
- `local_kbacompany_public_company`
- `local_kbacompany_public_employees`
- `local_kbacompany_public_organization`
- `local_kbacompany_public_positions`
- `local_kbacompany_public_indicators`
- `local_kbacompany_public_budgets`

## Security boundary

Every company-scoped function requires a stable BMS Company UUID. The authenticated Moodle service user must already be authorised for that company through existing BMS permission rules. Site-admin behavior remains explicit and unchanged.

Employee contact details are not returned by default. `includecontact=1` requires organization-management permission.

## Envelope

Every resource snapshot returns:

```text
api_version
schema_version
resource
company_uuid
workspace_uuid
generated_at
snapshot_hash
data
```

`snapshot_hash` is generated from the stable resource payload and excludes `generated_at`, allowing consumers to detect unchanged snapshots.

## Resource ownership rules

### Company
BMS Company UUID remains the canonical management identity. Workspace UUID is a projection owned by BMS Cloud and may be empty before Cloud onboarding.

### Employees
Employee identity remains Moodle User + historical BMS Company membership. The API exposes `moodle_user_id` and `membership_uuid`; it does not create a new Person entity.

### Organization
The API prefers canonical Organizing Board branches/nodes. Companies that still rely on the compatibility organization projection remain readable without fabricated hierarchy.

### Positions
Canonical Position UUID is exported. Internal assignment row IDs are not exported.

### Indicators
Indicator definitions and bindings are exported. Provider-specific integration IDs are excluded.

### Budgets
Only effective approved revisions are exported. Lines contain plan amounts and management limits. Accounting postings, invoices, payments, bank transactions and operational actual ledger data remain outside BMS ownership.

## Non-goals of v1 foundation

- no business write commands;
- no Cloud provisioning API;
- no Odoo database access;
- no Bitrix-specific public DTOs;
- no subscription or pricing data;
- no competency API before the canonical 0.7.x competency architecture;
- no course-completion-to-competency inference.

## Evolution

Future releases can add command endpoints only after explicit idempotency, revision/concurrency, audit and service-identity contracts are defined. Existing v1 resource DTOs should evolve additively where possible.
