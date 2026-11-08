# BMS24 — рабочий процесс продукта, Codex и релизов

Утверждено владельцем продукта 2026-11-08: `docs/adr/ADR-0001-bms24-decisions-and-release-governance.md`.
Стратегический контекст: `docs/22_BMS24_PRODUCT_VISION_FOUR_STREAMS.md`.

## Роли и источники истины

- ChatGPT/владелец продукта — постановка целей, обсуждение, принятие решений.
- GitHub ADR в `main` — долгосрочные архитектурные решения; GitHub Issues —
  задачи, требования и acceptance criteria.
- Codex и доступные субагенты — архитектурное ревью, реализация, QA и PR.
- GitHub CI — автоматические проверки; staging — доказательная приёмка.
- Production — отдельное конкретное разрешение владельца, не auto-deploy.

## Один повторяемый цикл

1. Обсудить цель, ожидаемое поведение и риски в чате BMS24.
2. Для изменения архитектуры/БД/прав/ИИ/интеграций оформить ADR. Получить
   явное утверждение владельца и положительное архитектурное ревью.
3. Создать GitHub Issue с критериями приёмки, рисками, приоритетом, зависимостями,
   ссылкой на утверждённый ADR и чётким определением результата.
4. Codex проверяет checkout: `git status`, реальный Git SHA, фактический
   `version.php`, источники и незавершённые PR. Выбирает базовую ветку.
5. Реализовать **маленький** PR, сохранить company UUID/RBAC и старый UI
   `legacy`, провести code review, security review и `python scripts/qa_local.py`.
6. Проверить на staging: backup, DB upgrade+rollback, Moodle PHPUnit,
   две компании, ACL/GET/POST, UI KBA+Boost и `legacy`+`owned`
   (в применимых случаях), smoke, реальные версии PHP/DB.
7. Приложить SHA ZIP и Git commit, test report, accepted risks, release notes,
   журнал развёртывания. Принять релиз как кандидата.
8. Если целевой контур — production, отдельно запросить и получить
   **разрешение владельца на этот конкретный артефакт и контур**.
   Произвести защищённое развёртывание и post-deploy smoke.
9. При неуспешном gate остановиться и исправить проблему отдельным PR.
   Операции, которые невозможно безопасно откатить, не автоматизировать
   без детального recovery plan.

## Существующие команды для локального разработчика

```powershell
.\scripts\start_feature.ps1 "task-name"
.\scripts\preflight.ps1
python scripts\build_release.py
```

Применять эти команды только после проверки конкретных файлов и среды.
Задачи ролей:
`docs/tasks/ARCHITECT_TASK_TEMPLATE.md`,
`docs/tasks/IMPLEMENT_TASK_TEMPLATE.md`,
`docs/tasks/REVIEW_TASK_TEMPLATE.md`.

**Статус инфраструктуры:** текущий CI на `main` выполняет статическую QA
и формирует ZIP. Работа Moodle PHPUnit, автоматического staging deploy и
production approval pipeline ещё не подтверждена. Не сообщать «готово» до
получения результатов.
