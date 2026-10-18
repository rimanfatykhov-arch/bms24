# KBA Connect v5.0.28

## Simplified Production Wizard

Changed:
- completely simplified Production Wizard UI;
- removed duplicate page title;
- removed editable Controlled Deal ID from normal workflow;
- removed editable STAGE_ID from Production Wizard;
- removed raw JSON from normal workflow;
- replaced magic confirmation text with checkboxes;
- added four high-level state cards;
- added compact readiness table;
- Production Finalize now requires CRM fields to be ready first and does not
  provision them synchronously;
- added direct links to CRM Fields, Bitrix24 and Funnel/Stage settings;
- queue smoke test moved under technical details;
- technical execution data collapsed by default;
- SAFE OFF remains available.

Safety:
- no production state changed by upgrade.
