# KBA AI Assessor 0.9.1 — BMS Assistant Profile Foundation

Build `2026092201`. Adds a structured proposal-only BMS Assistant service for BMS CRUD while preserving all existing assessment, rubric, file analysis and Supervisor Assistant behavior. No database schema changes.

See `BMS_ASSISTANT_PROFILE_0.9.1.md`.

# KBA AI Assessor 0.9.0


## 0.9.0 — Visual Rubric & Teacher Review

- Adds a visual per-KBA-Element rubric builder at `/local/kbaai/rubric.php?cmid=...`.
- Removes the need for teachers to edit Rubric JSON directly.
- Stores criteria and performance levels in dedicated XMLDB tables while keeping `rubricjson` synchronised for backwards compatibility.
- Validates that criterion maximums equal the KBA Element maximum grade before assessment.
- Includes rubric levels in the immutable assessment snapshot sent to the AI provider.
- Upgrades the teacher review panel with criterion-by-criterion scores, rationale, evidence, qualitative confidence, AI teacher note and suggested learner feedback.
- Records the source of the published grade: AI Examiner, AI confirmed by teacher, or AI adjusted by teacher.
- Existing 0.8.x assessment history, Yandex, PDF, DOCX, XLSX and Supervisor Assistant data are preserved.

Moodle 4.0+ AI assessment engine for KBA Element.

## 0.8.2 highlights — section-based administration

The administration interface is now grouped in a dedicated Moodle category:

`Site administration → Plugins → Local plugins → KBA AI Assessor`

Direct category URL:

`/admin/category.php?category=local_kbaai_admin`

Sections:

- Overview;
- General settings;
- Yandex AI Studio;
- Documents and files;
- Supervisor AI assistant;
- KBA Element integration;
- AI provider diagnostics;
- KBA Element diagnostics.

The **Check everything: text, image, PDF, DOCX and XLSX** action is permanently available on the Overview page and the provider diagnostics page.

## Existing XLSX (Excel) support

Official `.xlsx` workbooks are now part of the assessment target. KBA AI Assessor safely reads the OOXML package locally and normalizes:

- worksheet names and visibility state;
- non-empty cell values;
- formulas and available cached results;
- Excel dates/times when number formats are recognizable;
- merged ranges;
- Excel table names and ranges;
- multiple worksheets, including hidden worksheets.

The plugin never executes workbook formulas, macros, external links, Power Query, OLE/embedded objects or active content. External links are not followed. Charts, drawings and embedded objects are not treated as evidence in 0.8.1.

If extraction is incomplete, the attachment receives `partial`, the assessment is routed to `needsreview`, and AI Examiner auto-publication is blocked.

Default XLSX limits:

- 15 MiB per XLSX;
- at most 2 XLSX files per assessment;
- 20 worksheets per workbook;
- 10,000 non-empty cells per workbook;
- 2,000 rows per worksheet;
- 80,000 normalized characters in the AI context.

## Existing document support

- text/CSV/Markdown/JSON;
- PNG/JPG/WebP through the configured multimodal model;
- PDF through asynchronous Yandex Vision OCR;
- DOCX through local OOXML extraction plus multimodal analysis of referenced raster images.


## Diagnostics in 0.8.2
Use **Check everything** to run text, image, PDF, DOCX and XLSX checks. The quick check tests only the text API; DOCX/XLSX local diagnostics still run.


## 0.8.3 — Supervisor Assistant UI Patch
- Harmonises the supervisor assistant page title with the learner-facing identity.
- Russian: `ИИ-ПОМОЩНИК СУПЕРВАЙЗЕРА`.
- English: `SUPERVISOR AI ASSISTANT`.
- Product/admin name remains `KBA AI Assessor`.
- No database schema changes.
