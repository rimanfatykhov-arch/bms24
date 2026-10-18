# KBA Connect v5.0.31 — Release Manifest

Release: CRM Field Engine Rebase
Base: v5.0.30 Message Templates Restore
Reference implementation: local_bitrixsync v0.4.6

Observed production failures:
1. undefined `str_ends_with`;
2. repeated CRM userfield page for Contact;
3. repeated CRM userfield page for Deal.

All three are addressed by restoring the proven old transport/pagination
contract while retaining v5 field mapping and production logic.

No automatic production/inbound state is enabled by upgrade.
