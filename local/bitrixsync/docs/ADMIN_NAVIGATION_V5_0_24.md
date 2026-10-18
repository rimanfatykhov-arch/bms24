# KBA Connect v5.0.24 — Full Admin Navigation & UX Migration

## Why v5.0.23 looked unchanged

v5.0.23 created new pages but did not replace the existing Moodle
`settings.php` admin tree. Therefore Moodle continued to show the old giant
settings page and the long list of technical links.

v5.0.24 replaces the admin tree itself.

## New working navigation

- Dashboard
- Setup Center
- Bitrix24
- CRM Fields
- Courses & Products
- Corporate
- Automation
- History
- Diagnostics
- Production
- Advanced settings

## Bitrix24 page

The connection screen explicitly shows both directions:

1. Moodle → Bitrix24
   - incoming REST webhook created in Bitrix24
   - allowed deal stages
   - REST connection test

2. Bitrix24 → Moodle
   - generated KBA Connect v5 endpoint
   - read-only URL for the Bitrix24 robot/event

## CRM Fields

Displays Company / Contact / Deal KBA fields and their real cached mapping.
Buttons:
- Check fields
- Create missing / synchronize

## Setup Center

The administrator follows one sequence:

1. Bitrix24
2. CRM Fields
3. Courses & Products
4. Corporate
5. Automation
6. Production

## What is hidden

Legacy migration pages, test pages, duplicate production pages, internal
monitoring prototypes and CLI-oriented pages remain accessible by direct URL
for support, but are removed from the normal Moodle admin navigation.

## Core safety

This release changes administration UX only.
It does not enable inbound, change engine mode, or run deals automatically.
