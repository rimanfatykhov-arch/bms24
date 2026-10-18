# AI DECISION LOG — KBA Connect v5.0.36.1.18

## Decision
BMS company admission must use an administrator-selected exact Bitrix24 Company field.

## Reason
Automatic XML_ID/label/legacy fallback is useful for field recovery/provisioning but is too ambiguous for a production business gate that determines whether a CRM Company becomes a canonical BMS Company.

## Contract
- selection is stored as the exact Bitrix FIELD_NAME;
- only single boolean Company fields are offered;
- no selection means synchronization is paused;
- production bridge and diagnostics use the same resolver;
- CRM Field Engine provisioning remains available but no longer decides the production BMS admission field automatically.
