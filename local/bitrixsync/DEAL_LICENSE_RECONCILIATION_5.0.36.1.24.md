# Deal / License Reconciliation — 5.0.36.1.24

The reconciliation service compares live Bitrix24 Deal product rows with KBA Corporate licence packages using the durable key:

`Bitrix Company ID + Deal ID + Product Row ID + Course`.

For every mapped product row it reports:
- expected seats from Bitrix `QUANTITY`;
- current Corporate package seats;
- missing package;
- quantity drift;
- in-sync state.

Apply mode calls only the Corporate licence-package sync API. It never calls seat allocation and never changes Moodle enrolments.
