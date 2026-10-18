# KBA Connect v5.0.27

## Secure Outgoing Webhook Tokens

Restored from v0.4.6:
- Application Token for Deal outgoing webhook;
- optional Contact outgoing webhook token;
- optional Contact event switch.

Security:
- webhook_v5 accepts POST only;
- validates KBA URL secret;
- validates Bitrix24 auth.application_token;
- validates Bitrix24 auth.domain when supplied;
- supports ONCRMDEALUPDATE and ONCRMDEALADD;
- authenticates optional ONCRMCONTACTUPDATE.

Admin UX:
- Bitrix24 page clearly separates Moodle→Bitrix24 and Bitrix24→Moodle;
- shows exact outgoing webhook event setup;
- secret token inputs never reveal stored values;
- Dashboard / Setup Center require Deal Application Token for green Bitrix24 status;
- Production Readiness includes outgoing Application Token.

Upgrade safety:
- automatic v5 inbound is turned OFF.
