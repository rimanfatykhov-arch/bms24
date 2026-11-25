# BMOS-A0 — Architecture Foundation (пакет документов)

**Статус: PROPOSED, ревизия 2.** Направление BMS-09, issue #76, проверка #84. Исполнитель: Claude. 24–25.11.2026.
Направление условно утверждено владельцем 25.11.2026 (Q-01–Q-18); gate A0 закрывается после независимого PASS Codex.
База: `main` 62fdc4934b8b8a87fb8c7f21a52c56b64229e836.

**Платформа агентов не реализована.** Этот пакет — архитектура для решения владельца и проверки Codex. Он не
создаёт таблиц, функций, процессов, ключей, сетевых вызовов и не меняет код.

| Документ | Содержание |
|---|---|
| [ADR-0013](../adr/ADR-0013-bmos-work-principal-agent-platform-PROPOSED.md) | Решение (PROPOSED) |
| [A0_00_AUDIT](A0_00_AUDIT.md) | Аудит существующего кода и источников: что изучено и что нет |
| [A0_01_ARCHITECTURE](A0_01_ARCHITECTURE.md) | Слои, путь вызова, размещение, противоречия и решения |
| [A0_02_WORK_PRINCIPAL_AND_OCCUPANCY](A0_02_WORK_PRINCIPAL_AND_OCCUPANCY.md) | WorkPrincipal, Position Occupancy, гранты, секреты, атрибуция |
| [A0_03_AGENT_SECURITY_MODEL](A0_03_AGENT_SECURITY_MODEL.md) | Цепочка проверок, модель угроз T01–T28, kill switch |
| [A0_04_AGENT_API_V2_CONTRACT](A0_04_AGENT_API_V2_CONTRACT.md) | Команды, риск-классы, envelope, ошибки |
| [A0_05_MODEL_PROVIDER_CONTRACT](A0_05_MODEL_PROVIDER_CONTRACT.md) | ModelProviderInterface, Router, адаптер Timeweb |
| [A0_06_AGENT_RUN_AND_AUDIT_CONTRACT](A0_06_AGENT_RUN_AND_AUDIT_CONTRACT.md) | AgentDefinition, AgentRun, аудит, AI Billing |
| [A0_07_HUMAN_APPROVAL_CONTRACT](A0_07_HUMAN_APPROVAL_CONTRACT.md) | Подтверждение человеком |
| [A0_08_ERD](A0_08_ERD.md) | ERD и ключи |
| [A0_09_ACCEPTANCE_MATRIX](A0_09_ACCEPTANCE_MATRIX.md) | Трассировка 22 исходных тестов, сценарии S01–S35 (все NOT RUN) |
| [A0_10_ROADMAP_BACKLOG_MIGRATIONS](A0_10_ROADMAP_BACKLOG_MIGRATIONS.md) | Этапы A0–A4, backlog, план миграций |
| [A0_11_OWNER_DECISIONS](A0_11_OWNER_DECISIONS.md) | Решения владельца Q-01–Q-18 и история |
| [A0_12_AGENT_RUNTIME](A0_12_AGENT_RUNTIME.md) | `local_bmosruntime`: границы доверия, kill switch, in-flight |
| [A0_13_CLOSURE_MATRIX](A0_13_CLOSURE_MATRIX.md) | Закрытие REV-01–REV-10, replay и пунктов поручения |
| [prototype/index.html](prototype/index.html) | Статичные прототипы: занятость постов, карточка агента, подтверждения, отчёты команды |

Связанные открытые PR, которые этот пакет продолжает, а не дублирует: #77 (ADR-0007, слой управления ИИ),
#89 (SIPOC handoff), #97 (согласование MAX/BMOS/SaaS), #88 (контракт MAX), #178/#179 (MAX fake slice, ADR-0012).
SIPOC handoff (#77/#89) отнесён к A4 и здесь не переопределяется.
