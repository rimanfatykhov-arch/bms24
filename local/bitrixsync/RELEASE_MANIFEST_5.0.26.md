# KBA Connect v5.0.26 — Release Manifest

Release: Async CRM Field Sync
Base: v5.0.25 Bitrix24 Funnel & Stage Selector

Observed issue:
CRM Fields page hangs while synchronously provisioning missing Contact and Deal
fields in Bitrix24.

Resolution:
All CRM field remote work moved to background cron processing.

No production state is enabled or changed by upgrade.
