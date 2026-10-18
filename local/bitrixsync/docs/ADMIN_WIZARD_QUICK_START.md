# Quick Start — Admin Wizard

After installing v5.0.14.4:

1. Open Moodle:
   `Администрирование → Плагины → KBA Connect → Мастер запуска Production`

2. Confirm Step 1 is OK.

3. In Step 2 enter:
   `FINALIZE_3761`
   and click **Финализировать Production**.

4. Run Step 3:
   `CRM_TEST_3761`

5. Configure the displayed webhook URL in Bitrix24.

6. Confirm Step 4:
   `BITRIX_EVENT_3761`

7. Enable inbound in Step 5:
   `ENABLE_INBOUND_3761`

8. Run queue smoke:
   `QUEUE_3761`

Emergency stop at any time:
`SAFE_OFF_3761`
