# KBA Connect v5.0.36.1.20 — Resolved BMS Field Selector Fix

Production hotfix for the explicit BMS Company admission-field selector.

The previous selector interpreted the CRM Field Engine cache as a raw Bitrix field list. The cache is actually keyed by logical field names (`company -> bms_client`). This release exposes those resolved rows directly, exactly like `crm_fields.php`, and resolves a saved FIELD_NAME against the same verified cache before trying live REST.

Expected result: a field that is shown as Ready on CRM Fields, such as `KBA — Клиент BMS [UF_CRM_...] / KBA_CONNECT_BMS_CLIENT`, is available in the Moodle settings selector even when the live Bitrix userfield-list request is unavailable.

Schema-neutral. Deal-driven enrolment is unchanged.
