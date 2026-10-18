# KBA Connect v5.0.14.5 — Bitrix REST Hardening

## Problem reproduced

Production Finalize reached CRM field provisioning and failed on:

`crm.company.userfield.list`

with `bitrix_invalid_json`.

The controlled Moodle + Corporate execution is already proven and remains
untouched. Automatic inbound stays OFF.

## Changes

### REST transport hardening

Primary transport remains the existing form-urlencoded request used by the
working production engine.

For READ-ONLY methods only (`*.get`, `*.list`, `*.fields`, `profile`), when the
first response cannot be decoded as JSON, KBA Connect retries exactly once with:

- `.json` transport suffix;
- `Content-Type: application/json`;
- JSON request body;
- redirects enabled;
- response decompression enabled;
- userfield timeout of at least 45 seconds.

Mutating REST methods are never automatically retried.

### Better diagnostics

Invalid JSON errors now include:
- REST method;
- HTTP code;
- Content-Type;
- response byte count;
- JSON decoder error;
- transport used;
- short sanitized body preview.

Webhook URL/token is not included.

## New diagnostics

CLI:

`php local/bitrixsync/cli/bitrix_crm_rest_probe.php`

Moodle admin:

`KBA Connect → Диагностика Bitrix24 REST`

The probe checks:
- profile
- crm.company.userfield.list
- crm.contact.userfield.list
- crm.deal.userfield.list

## Retry sequence

1. Install v5.0.14.5.
2. Run `bitrix_crm_rest_probe.php`.
3. Probe must return `"ready": true`.
4. Return to Production Setup Wizard.
5. Run Finalize again (`FINALIZE_3761`).
