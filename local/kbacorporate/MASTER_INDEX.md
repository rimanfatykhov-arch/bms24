# KBA Corporate Dashboard — MASTER INDEX

## Product

- Product/UI name: KBA Corporate Dashboard
- Moodle component: `local_kbacorporate`
- Minimum Moodle: 4.0
- Role: управление аудитами BMS, внедрением, компетенциями, обучением и экономической аналитикой

## Current prepared release

- Release: `0.40.0 — Production Hardening`
- Build: `2026091009`
- Status: RC / production hardening
- Upgrade path: in-place from all supported earlier `local_kbacorporate` builds
- Schema impact: one additive privacy-safe ERP operational audit table

## Access model

- `manageimplementationaudits`: глобальная методология, Framework Manager, инструменты, mappings и JSON administration.
- `managecompanyimplementation`: клиентские аудиты, планы, задачи, доказательства и аналитика только для компаний с полной manager membership.
- Curator assignment: рабочее пространство закреплённых программ и глобальный Kanban в пределах кураторской выборки.
- Company membership remains mandatory; capability alone does not bypass tenant isolation.

## Current architecture flow

`компания → аудит BMS → разрывы → действия → задачи → доказательства → повторный аудит → сравнение до/после → economic context`.

Параллельный контур:

`бизнес-роль → компетенции → учебный план Moodle → ИПР KBA → повторная оценка`.

## Key entry points

- Company dashboard: `index.php`
- Customer BMS workspace: `implementation.php`
- Audits: `implementation_audits.php`
- Audit comparison: `implementation_audit_comparison.php`
- Company tasks: `implementation_tasks.php`
- Evidence workspace: `implementation_evidence.php`
- Section progress: `implementation_section_progress.php`
- Economic registry: `economic_indicators.php`
- Global methodology: `framework.php`, `bms_tools.php` (high privilege only)
- Curator/global board: `implementation_board.php`

## Release artifacts

- `RELEASE_NOTES_0.40.0.md`
- `CHANGELOG_0.40.0.md`
- `TEST_REPORT_0.40.0.md`
- `AI_DECISION_LOG_0.40.0.md`
- `RELEASE_MANIFEST_0.40.0.md`
- `KBA_Corporate_Dashboard_v0.40.0_Production_Hardening_full.zip`
- companion SHA-256 file

## Known limitations

- Cross-company Kanban remains curator/BMS-admin only.
- Runtime role-cache, access and browser tests are performed on the pilot Moodle.
- Final production baseline remains gated by pilot deployment and acceptance testing.
