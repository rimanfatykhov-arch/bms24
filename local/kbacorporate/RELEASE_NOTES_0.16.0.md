# KBA Corporate Dashboard 0.16.0

## Competency Development — этап 1

Добавлен read-only раздел **«Мои компетенции»** на данных стандартной системы компетенций Moodle.

### Что входит
- `/local/kbacorporate/mycompetencies.php` для обучаемого;
- просмотр компетенций сотрудника корпоративным менеджером из карточки сотрудника;
- Learning Plans и назначенные компетенции;
- текущий уровень, целевой уровень и GAP;
- статусы: «Не начато», «В развитии», «Требует подтверждения», «Подтверждено», «Требует внимания»;
- evidence из Moodle;
- источник evidence **«AI Оценка»**, когда evidence действительно поступил от KBA AI Assessor;
- пилотное целевое профилирование C01–C12 для BMS Начало согласно методологической карте.

### Архитектурное решение
KBA Corporate Dashboard не дублирует `competency`, `competency_usercomp`, `competency_plan`, `competency_plancomp` и `competency_evidence`. Moodle остаётся master-системой компетенций.

### Следующий этап
- профили компетенций должностей;
- целевые уровни по роли;
- автоматический Skill GAP;
- индивидуальный план развития;
- HR-карта компетенций сотрудников;
- явный API-контракт KBA AI Assessor → Moodle competency evidence.
