# KBA Connect v5.0.27 — Secure Outgoing Webhook Tokens

This release restores the proven v0.4.6 webhook administration model.

## Moodle → Bitrix24

In KBA Connect → Bitrix24, paste the Bitrix24 incoming REST webhook:
`https://portal.bitrix24.ru/rest/USER_ID/WEBHOOK_CODE/`

This is a secret used by Moodle to read/update CRM.

## Bitrix24 → Moodle

Create a Bitrix24 outgoing webhook:

- event: `ONCRMDEALUPDATE`
- optional event: `ONCRMDEALADD`
- URL: the `webhook_v5.php?key=...` URL shown by KBA Connect
- after saving, copy Bitrix24 **Application Token** into
  `Application Token исходящего webhook Сделки`

KBA Connect requires both:
1. the KBA secret URL key;
2. the Bitrix24 Application Token.

When Bitrix24 sends `auth.domain`, KBA Connect also checks the portal domain
against the configured Moodle→Bitrix24 REST webhook.

## Contact webhook

The v0.4.6 Contact token is restored as an optional setting:

- event: `ONCRMCONTACTUPDATE`
- same Moodle handler URL;
- separate Contact Application Token is optional;
- if blank, Deal Application Token is used.

Contact-only events do not trigger course enrolment.

## Upgrade safety

`v5_inbound_enabled` is set OFF during upgrade.
After saving the Application Token, repeat Production readiness checks before
turning automatic inbound on again.
