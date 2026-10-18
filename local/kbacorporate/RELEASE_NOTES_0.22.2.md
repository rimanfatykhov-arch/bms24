# KBA Corporate Dashboard 0.22.2

Build: `2026090250`

Administrators no longer need to copy a pipeline ID, stage code or responsible-user ID manually. The new Bitrix24 program selector loads CRM deal categories, stages for the chosen category and active users through the configured incoming webhook.

The created deal receives `CATEGORY_ID`, `STAGE_ID` and, when selected, `ASSIGNED_BY_ID`. Access to the selector requires Moodle site configuration permission. The webhook remains protected in plugin settings.

Upgrade directly over v0.22.1 without uninstalling the plugin. Save the webhook first, then open “Bitrix24 implementation-program pipeline”, select a pipeline, load its stages, select the starting stage and responsible person, and save.
