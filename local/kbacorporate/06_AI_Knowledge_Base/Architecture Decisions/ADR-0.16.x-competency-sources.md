# ADR — 0.16.x Competency Sources

## Decision
`Мои компетенции` aggregates competencies from active enrolled courses first, optional Learning Plans second, and existing user competency/evidence records as a completeness fallback. Duplicate competencies are merged by competency ID.

## Ownership
Moodle core remains system of record for competency frameworks, course competency mappings, user competency state and evidence. KBA Corporate Dashboard is a reader/presenter and does not create a duplicate competency-result store.

## Confirmation
Evidence can indicate development progress but does not automatically confirm proficiency. Explicit proficiency remains required for `Подтверждено`.

## Terminology
User-facing terms: `Проверка`, `AI Оценка`, `Разрыв до цели`.
