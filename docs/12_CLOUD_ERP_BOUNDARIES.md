# BMS Cloud / ERP Boundary Notes

## BMS Cloud

Target:
- standalone Control Plane
- own PostgreSQL
- modular monolith
- not a Moodle local plugin pretending to be infrastructure control plane
- not premature microservices

Cloud concepts:
Customer, Workspace, Entitlements, Applications, Environment, Domain, Provisioning,
Release/Deployment metadata, Health, Backup metadata, Infrastructure audit.

## BMS ERP / Odoo

Odoo is an operational engine, not the owner of the management model.

Early preferred hosting may be Odoo.sh, but architecture must be hosting-neutral.

## Upgrade path

BMS Start → BMS ERP should be an upgrade, not destructive migration.

Preserve canonical:
- Company UUID
- management history
- employees
- roles
- indicators
- budgets
- other BMS identities

Mappings connect these to ERP provider records.
