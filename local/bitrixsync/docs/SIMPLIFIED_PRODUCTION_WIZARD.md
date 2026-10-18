# KBA Connect v5.0.28 — Simplified Production Wizard

## Problem

The previous Production Wizard exposed too much engineering information:
- controlled Deal ID field;
- raw STAGE_ID text;
- large JSON proof blocks;
- typed confirmation phrases such as FINALIZE_3761;
- raw CRM verification JSON;
- technical queue details in the main workflow.

The attached production screenshot showed that the functional flow worked but
the page was difficult to administer.

## New workflow

The normal administrator sees only five steps:

1. Проверка готовности
2. Подготовка Production
3. Проверка записи в Bitrix24
4. Исходящий webhook Bitrix24
5. Автоматическая обработка

There is a separate SAFE OFF block.

## Simplifications

- Controlled Deal ID is no longer editable in the normal wizard.
- STAGE_ID is no longer editable here; stages are selected on the Bitrix24
  Funnel & Stage Selector page.
- Raw JSON is hidden.
- Magic typed phrases were replaced by an explicit confirmation checkbox.
- CRM field creation is not performed synchronously by Finalize.
  The wizard requires the asynchronous CRM Fields page to be green first.
- Technical data is available only under a collapsed "Техническая информация"
  section.

## Safety retained

Every state-changing action still requires:
- `local/bitrixsync:manage`;
- Moodle sesskey;
- explicit confirmation checkbox;
- all existing service-level production checks.

The upgrade does not enable production automatically.
