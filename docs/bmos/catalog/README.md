# CAT-0 — BMS Agent Catalog (пакет документов)

**Статус: PROPOSED, только документы.** Исполнитель: Claude. 28.11.2026. Направление BMS-09 (BMOS Agent Platform),
issue #76. Базовая ветка: `main` 0884643590541fdd9b75b1414fd93d6120ded017.

**Каталог не реализован.** Пакет описывает архитектуру, логический контракт и статический прототип для решения
владельца. Он не создаёт таблиц, функций, процессов, ключей, сетевых вызовов и не меняет код.

## Зависимость от BMOS-A0

CAT-0 опирается на контракты BMOS-A0 из PR #183, **ревизия 7** (`68b1dd07b1b75f1af94e6442e629d127f947b95b`, tree
`ae2254d1b2ab3708e9795e54f6285dd07c5f0e0e`). Независимая проверка Codex дала по ней **PASS**
([#84](https://github.com/rimanfatykhov-arch/bms24/issues/84#issuecomment-7229562938)); PR #183 не смержен. Пакет
CAT-0 сверен с ревизией 7. При новой ревизии A0 пакет сверяется заново. PASS A0 не открывает CAT-1…CAT-6: каждый
этап начинается только по отдельному поручению владельца.

## Методологическая основа (Google Drive, все — проекты v0.1)

| Документ | Ссылка |
|---|---|
| BMS-STD-DNA-001 Role DNA 2.0 | https://docs.google.com/document/d/1ZwvJ9AKNaAnZCuTZyBuVVrdwLE4gd1BtDonB-m5At6k/edit |
| BMS-STD-AEP-001 AI Execution Profile | https://docs.google.com/document/d/1lcdfcJi082eZYVWTWl5tfrQQ2WkMLG_F1-m4EFaeP6Y/edit |
| BMS-STD-REC-001 Recognition Standard | https://docs.google.com/document/d/18dhucaPSvoaO1DpfH7e5DrhEoZpegRzP1Zo7-0LH8qU/edit |
| BMS-STD-OCC-001 Position Occupancy | https://docs.google.com/document/d/1UrDeivGLoFCN-KwiexDzAvWoWXWD20u8MQtOdNCFYc8/edit |
| BMS-AGENT-001, папка | https://drive.google.com/drive/folders/1ftw2DWZFfZGww8JqSrfVeyA5BAFqJDp- |
| 00 Состав папки и паспорт пилота | https://docs.google.com/document/d/1Tq4dPZhgXDL4YMpivAJLrhaVf5kmWHRLBMuuK4BM3SU/edit |
| 01 Функциональный пост и Role DNA | https://docs.google.com/document/d/1w8ZUhSfGFHXxAkZyEKTZ7biu3n2-kMILOhscJ7WXZaY/edit |
| 02 AI Execution Profile и Position Occupancy | https://docs.google.com/document/d/1-z0TjBuu5hQJAarmwioLzronZQC4w9onQ9vA4bAFxNs/edit |
| 03 Recognition, кейсы и рабочие стандарты | https://docs.google.com/document/d/1-V_QyJU_atIm2fevUiEYJnRtlG0cdP1Dnc_5lreJ9nE/edit |

Все восемь документов прочитаны 28.11.2026 через Drive. Они имеют статус «проект к утверждению» и здесь не
выдаются за утверждённые стандарты. Зеркало текстов в GitHub этим PR **не создаётся** (см. «Синхронизация»).

## Состав пакета

| Документ | Содержание |
|---|---|
| [CATALOG_ARCHITECTURE](CATALOG_ARCHITECTURE.md) | Аудит существующего кода, десять сущностей, место каталога в BMOS |
| [CATALOG_TEMPLATE_CONTRACT](CATALOG_TEMPLATE_CONTRACT.md) | Логическая модель шаблона и редакции (не DDL) |
| [CATALOG_OWNERSHIP_MATRIX](CATALOG_OWNERSHIP_MATRIX.md) | Кто чем владеет, физическое размещение и обоснование |
| [CATALOG_LIFECYCLE](CATALOG_LIFECYCLE.md) | Статусы, версии, мастер подготовки агента (12 шагов), обновления |
| [CATALOG_SECURITY](CATALOG_SECURITY.md) | Угрозы и инварианты каталога |
| [CATALOG_UX](CATALOG_UX.md) | Пользовательские сценарии и экраны; [статический прототип](prototype/index.html) |
| [CATALOG_ACCEPTANCE_MATRIX](CATALOG_ACCEPTANCE_MATRIX.md) | Критерии приёмки CAT-0: PASS / FAIL / NOT RUN |
| [CATALOG_ROADMAP](CATALOG_ROADMAP.md) | CAT-0…CAT-6, backlog, открытые решения |
| [AGENT-001](AGENT-001/README.md) | Первая карточка каталога и [проект manifest](AGENT-001/template.json) |

## Синхронизация документации

- Зеркало четырёх стандартов и четырёх документов AGENT-001 в GitHub — отдельный docs-only PR по поручению
  владельца; в этом PR не выполнено.
- `docs/DOCUMENT_REGISTER.md`, `docs/00_MASTER_CONTEXT.md` и `docs/product/MASTER_ROADMAP_3.md` правит открытый
  Draft PR #184 (`docs/master-roadmap-3-sync-20261124`). Чужую ветку этот PR не меняет. Предлагаемые записи реестра
  перечислены в [CATALOG_ROADMAP §5](CATALOG_ROADMAP.md).
- Drive-документы этим PR не изменены. Статус DoD синхронизации: **DOC-SYNC HOLD** до зеркала и readback.
