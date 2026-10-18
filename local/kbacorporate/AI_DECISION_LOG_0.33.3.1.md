# AI Decision Log v0.33.3.1

## Decision

Use the provider-neutral product term `ERP` in the user interface while preserving concrete provider identifiers in the integration layer.

## Reason

The product is intended to integrate with multiple ERP systems. Showing `Odoo` throughout the interface incorrectly couples the product presentation to one future provider and limits reuse for other ERP platforms.

## Preserved technical contract

The following identifiers are not renamed:

- provider token `odoo`;
- capability `local/kbacorporate:useerpapi`;
- service and function identifiers containing `erp`;
- existing mapping records;
- historical API examples and provider-specific technical documentation where the concrete provider matters.

Renaming these values would be a contract migration rather than a UI patch and could break existing integrations.

## Existing Moodle descriptions

Code-only replacement would leave old `Odoo` text in already-created Moodle templates and plans. A narrow migration was therefore added. It changes descriptions only when KBA service markers are present and does not touch unrelated user-created objects.

## Rejected alternatives

1. Rename the provider token from `odoo` to `erp` — rejected because it would break idempotent mappings and external clients.
2. Replace every occurrence in historical documentation — rejected because historical and provider-specific records must remain accurate.
3. Require manual resynchronisation of every template and plan — rejected because the interface would remain inconsistent immediately after upgrade.
