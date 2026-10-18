# KBA Connect v5.0.30

## Message Templates Restore

Restored:
- message templates admin section from v0.4.6;
- access email sender/channel/subject/body settings;
- Bitrix24 timeline common templates;
- original account/enrolment/group/progress/login/error/webinar templates.

Added:
- current v5 Deal timeline summary template;
- current v5 Company timeline summary template;
- Message Templates item in KBA Connect admin navigation;
- reset-to-default button.

Changed:
- crm_writeback_service no longer hardcodes Deal/Company timeline texts;
- current v5 timeline comments are rendered from administrator templates;
- legacy `timelineenabled` switch is respected together with v5 timeline enable.

Compatibility:
- existing v0.4.6 template values are preserved.
