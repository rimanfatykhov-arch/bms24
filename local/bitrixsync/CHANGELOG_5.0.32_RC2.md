# KBA Connect v5.0.32 RC2

## Corporate Role Schema Control Center

- Moved all Corporate Role Schema operational settings into the existing **Corporate** admin tab.
- Added internal Bearer token field to the Corporate page (empty value keeps the current secret).
- Added one-click secure token generation; generated secret is displayed only immediately after generation.
- Added live Corporate Dashboard/API/schema status card.
- Added Bitrix24 Contact field status, ID, UF_CRM code, type, MULTIPLE flag, and roles configured counter.
- Added explicit role contract table with Bitrix enum IDs.
- Added **Проверить схему** action.
- Added idempotent **Создать / восстановить поле** action.
- Existing backend endpoints and CorporateRoleSchemaService remain unchanged.
- No Bitrix24 credentials are moved into Corporate Dashboard.
