# BMS24 — реестр и синхронизация документации

Статус: принцип синхронизации утверждён владельцем; данный технический пакет ожидает review/merge. 24.11.2026.

## Источники и статусы
| Документ | Google Drive | GitHub |
|---|---|---|
| MASTER ROADMAP 3.0, MR3-D01–91 + MR3-A01 | [утверждённая карта](https://docs.google.com/document/d/1HlfPe-8CygWFL8j-zrx4rKOPD0alCMTxKhIM6KqutdM/edit) | [зеркало](product/MASTER_ROADMAP_3.md); инженерная карта #95/#117 (открыты) |
| Master Context v1.5.43 (внутренний заголовок) | [контекст](https://docs.google.com/document/d/1x4_0DyL8T2V2tHMEizIUdu0RWQD10yGbQZzZt_9EfFE/edit) | [инженерный контекст](00_MASTER_CONTEXT.md) |
| DOC / TECH v0.1, проект | [контракт](https://docs.google.com/document/d/1sD-po1ZSBTKZa_kGHVJ3vuJbobyyJytaubeJ3j7w3S4/edit) | [зеркало](architecture/BMS-ARC-DOC-001.md), #181 |
| FIN / TECH v0.1, проект | [контракт](https://docs.google.com/document/d/1v8Lo0-Ath8EbCki6TGtyZRE-L6GqAypOORycUwgwX80/edit) | [зеркало](architecture/BMS-ARC-FIN-001.md), FIN-W2 |
| BMOS A0–A4 | [AI Assistant v0.1, утверждён](https://docs.google.com/document/d/1Za6tcpW4EZage9w3XsnZnaWKU-cmVTcHO4gbBMhHmBA/edit) | #76 epic; #84 review; #183 ADR-0013 PROPOSED |
| Поставка | [Release Documentation Standard](https://docs.google.com/document/d/1CEfRTajkcrZ9v8BfTH5dd_RCPh_q3sXZjAAw3eL1n8s/edit) | [Release Conveyor v1](deploy/BMS24_RELEASE_CONVEYOR_V1.md), #160/#161 |
| Реестр релизов | [Changelog](https://docs.google.com/document/d/1vOlKC_Jayerh0KSuQDzHeACN5IAvAW_VV0uxGGqhib4/edit) | PR/CI/release receipts |
| Реестр модулей | [MASTER LIST](https://docs.google.com/spreadsheets/d/1XqK0hvZ3QAkB7JarwrtwagniywUd2dRsBAHBDZcCM5c/edit) | Issue/PR + module docs |

## Definition of Done — документирование
GitHub — источник технической истины (код, ADR, PR, CI, артефакт, квитанция); Drive — утверждённая управленческая, продуктовая и методологическая документация. При конфликте не переписывать решение молча: указать ID, версию, источник, статус, владельца, согласовать противоречие. Утверждение продуктового направления не утверждает DDL/API и не означает реализацию.
1. До работы проверить main SHA, AGENTS, действующие ADR, открытые PR/Issues, владельца ветки, соответствующие ссылки Drive. Не создавать конкурирующие документы и задачи.
2. В PR внести предметные изменения, ADR при необходимости, acceptance matrix, changed paths, риск, rollback, handoff. Для документационного PR не требовать установочный ZIP, если нет runtime/release изменений.
3. При изменении архитектуры или плана обновить соответствующий Drive документ с сохранением истории и readback; GitHub PR содержит ссылку, версию и статус. Не копировать секреты/данные клиентов.
4. При выпуске соблюдать Release Conveyor v1: код, CI, TEST, решение владельца, production receipt — разные факты. Синхронизировать Master Context, Roadmap, Changelog и Master List согласно релизному стандарту; сохранять PASS/FAIL/NOT RUN и идентификаторы доказательств.
5. ChatGPT — постановка/архитектура/синхронизация; Claude — продуктовый код и техническая документация; Codex — независимый review/оператор. Владелец утверждает решения и отдельный production GO.
6. Если доступ к Drive/GitHub отсутствует, зафиксировать DOC-SYNC HOLD, конкретный документ и ответственного; не заявлять синхронизацию выполненной. Не блокировать независимую безопасную разработку без установленной зависимости.

## Координация
#95 и #117 — существующая инженерная карта, не создавать новый конкурирующий docs/ROADMAP.md; согласовать сведение с владельцами. #161 редактирует AGENTS.md; дополнение политики должно быть сохранено при rebase/merge, не заменять его процесс. #181 и #183 открыты, не считать принятой функциональностью. A0–A4 — последовательные gates; ADR-0013 пока PROPOSED. A1 только после A0, fake provider, без сетевых вызовов с клиентскими данными. FIN-W2 — schema-neutral, координация общих файлов с #181 и отчётами/MAX.
