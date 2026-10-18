# 0.9.3 — build 2026092213

Диалог BMS с уточнениями, профили AI 0.3, readiness и защита устаревших ответов.
См. RELEASE_NOTES_0.9.3.md. Схема БД не изменена.

# Changelog

## 0.9.1 (2026092201)

- Added provider-neutral `bms_assistant_service`.
- Added structured BMS proposal contract for Explain / Interview / Draft / Review / Evidence Check.
- Added Yandex structured-output implementation and mock-provider support.
- Proposal-only contract: no canonical BMS writes from AI.
- No database schema changes.

## 0.9.0 (2026081602)

- Visual rubric builder with criteria and performance levels.
- New rubric XMLDB tables and upgrade path from 0.8.x.
- Rubric total validation against KBA Element maximum grade.
- Rich teacher review panel with criterion rationale/evidence.
- Published grade source tracking (`ai_examiner`, `ai_confirmed`, `teacher_adjusted`).
- Legacy `rubricjson` remains readable and is migrated when the visual rubric is saved.
- No deletion of existing AI assessments or supervisor conversations.

## 0.8.2 (2026081521)
- Added dedicated Moodle administration category `local_kbaai_admin`.
- Split configuration into General, Yandex, Documents, Supervisor Assistant, and KBA Element Integration sections.
- Added an Overview page with version/build information, system status, section cards, and persistent diagnostic buttons.
- Registered provider diagnostics and KBA Element diagnostics as separate admin navigation items.
- Added new versioned diagnostic pages `provider_test_v082.php` and `diagnostics_v082.php`.
- Preserved old `/admin/settings.php?section=local_kbaai` bookmarks through a hidden compatibility page.
- No database schema changes.

## 0.8.1 (2026081520)
- Clarified quick vs full Yandex diagnostics.
- The primary button now checks text, image, PDF, DOCX and XLSX.
- Skipped external checks are labelled as not run in the current check, not as failures.
- Added a notice explaining that DOCX/XLSX tests are local and always run.


## 0.8.0 / dev4.8 — XLSX (Excel)

- Added official-submission XLSX detection with 15 MiB and per-assessment count limits.
- Added safe local OOXML workbook extraction using PHP ZipArchive and DOMDocument.
- Extracts sheet names/states, cell values, formulas with available cached results, merged ranges and Excel table definitions.
- Recognizes common Excel date/time number formats and emits readable ISO-like values.
- Includes hidden sheets but marks their state in evidence.
- Never executes formulas, macros, external links, embedded objects, charts or drawings.
- External links, macro parts, drawings/charts, embedded objects, missing cached formula results and extraction limits force `partial`, teacher review and block Examiner auto-publication.
- Added administrator settings for XLSX character, sheet, cell and row limits.
- Added synthetic XLSX self-test and new versioned diagnostic pages.
- Added `yandex_provider_v080` to bypass stale provider OPcache.
- Product version: 0.8.0; Moodle build: 2026081519.

## 0.7.1

- Fixed Yandex PDF OCR: `application/pdf` instead of `PDF`.
- PDF recognition uses `recognizeTextAsync` and polls `getRecognition`.
- Added JSONL page parsing and operation ID diagnostics.

## 0.7.0 / dev4.7 — DOCX (Word)

- Added safe local DOCX extraction and multimodal analysis of referenced raster images.

## 0.6.1

- Added learner notification after an AI assessment is actually published.
