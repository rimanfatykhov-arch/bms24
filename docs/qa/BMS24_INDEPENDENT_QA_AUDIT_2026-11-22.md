# BMS24 Independent QA Audit — 22.11.2026

## Актуализация перед публикацией отчёта

Отчёт сохраняет результаты серверного/браузерного измерения22.11; повторная проверка GitHub перед созданием этого PR установила:

- #150 merged как `4c84019237837fa63fc835cda2f5f6ac03c39775`; head `7ea1e84147d990a19b409265cead1b863e8a64a0`. Исправлена проверка integration pin: точные PR head/base сохраняются, интеграционный пин проверяется через ancestry. [run44942988131](https://github.com/rimanfatykhov-arch/bms24/actions/runs/44942988131): package/MariaDB/Percona SUCCESS. [Merge gate44942987986](https://github.com/rimanfatykhov-arch/bms24/actions/runs/44942987986) SUCCESS. D-REL03 устранён на новом SHA; это не меняет исторический FAIL пакета#148 и не доказывает установку нового CI ZIP на production.
- #144 merged22.11.2026,10:59МСК; текущий main `36cc7ddc284f9d22c6e145aee59d64d1a856b665`. Стандарт больше не считается открытым предложением. Он принят в Git, но сам merge документа не создаёт SSH enforcement.
- Серверные и UX-измерения ниже не переносились на другой установленный пакет: повторного deploy или нового runtime-аудита не было. #145 остаётся OPEN.

## 1. Executive Summary

**Вердикт: HOLD для признания Коммуникационного центра принятым и конвейера технически защищённым.** Это не рекомендация немедленного отката работающего production. P0 не подтверждены; отсутствие P0 не означает пройденную security-приёмку.

Аудит выполнен Codex независимо: GitHub API/Actions, чтение production через существующий SSH, браузер test после входа владельца, локальные безопасные тесты валидатора. PHP/JS/CSS/Mustache, AGENTS, workflow, стандарт #144 и ветки DeepSeek не изменялись. Установка, миграции, отправка/правка сообщений, изменение прочитанности, членства, файлов и прав не выполнялись. Системные session/access logs при обычном просмотре не приравниваются к тестовым бизнес-записям.

Подтверждены: production BMS2026111601/Moodle4.1.22, maintenance OFF, 710 таблиц, scheduler недавно выполнялся; код PHP/JS/CSS/Mustache совпадает с CI ZIP #148. Полная byte identity не подтверждается: 19 служебных файлов отличаются окончаниями строк. Заявленный deployment ZIP отличается от доступного CI ZIP. На test воспроизведены сырые языковые ключи, слипание метаданных списка и ненадлежащее оформление controls/empty state. #144 описывает gates, но не закрывает обход через SSH. У #148 обе репетиции исторически FAIL до установки Moodle из-за package PR identity/base, несмотря на зелёный Merge gate; исправление #150 и обе успешные репетиции подтверждены отдельно, см. актуализацию.

Ранее накопленный контекст 19.11 устарел относительно новых публикаций; текущие выводы основаны на измерениях 22.11, а не на старом HANDOFF.

## 2. Production Verification

Целевой сайт: https://edu.kzn.mba . Read-only измерения 22.11.2026 около 10:18 МСК; время каждого SSH-сеанса сохранено в приватных локальных логах. Проверки не доказывают неизменность файлов после этого момента.

| Проверка | Результат | Доказательство/ограничение |
|---|---|---|
| BMS DB version | PASS: 2026111601 | SELECT через штатный Moodle DB API |
| Moodle | PASS: 4.1.22, Build20251208; core2022112822 | runtime configuration |
| PHP / DB | PASS:8.1.34 / Percona8.0.46-37 | runtime |
| Corporate | PASS:2026111100; isemployee DEFAULT0/status draft | runtime; пакет Corporate не менялся аудитом |
| Установленный модуль | 1831 файл | полная SHA256-инвентаризация |
| Maintenance | PASS:0, CLI marker отсутствует | runtime + filesystem |
| Upgrade | PASS:upgraderunning=false | SELECT; миграция не запускалась |
| Cron | PASS ограниченный: crontab совпадает с сохранённым #120; MAX(task_scheduled.lastruntime)=1795331942 | подтверждён запуск scheduler, НЕ успешность каждой задачи/очереди |
| БД | PASS ограниченный:710таблиц, SELECT доступен, @@read_only=0 | не CHECK TABLE/не доказательство полной целостности или сохранности всех бизнес-строк |
| dailyreports_enabled | false | состояние, не дефект данного CC-выпуска |
| Ошибки | NOT VERIFIED для общего утверждения «ошибок нет» | активный edu error log5274байта;16строк распознаны как21–22.11,11содержат error; PHP Fatal/Warning/Notice не найдены. Причины11строк не атрибутированы, пользовательские URL/строки не публиковались |
| Последний backup/полное восстановление | NOT VERIFIED | обнаружены DB-dumps и старый code backup; целостность/полнота свежего комплекта и реальное восстановление в этом аудите не проверялись |

### Дерево и артефакт

Алгоритм этого аудита: SHA256 от строк `relative_POSIX_path:SHA256(file)`, отсортированных по пути, соединённых LF **без завершающего LF**. Это файловая контрольная сумма, НЕ Git tree OID.

- Runtime tree: `56a0aecdc32b558d05b859c8c4d7697187fc2e3d2db8d4885e784d6d971bf41e`.
- Corporate runtime tree: `f6fc849adbad8338cf8ab8d6c69a16323782281949e85d10ccc4ca9df2840ac7`.
- GitHub main: `4c84019237837fa63fc835cda2f5f6ac03c39775`.
- #148 head: `927a319c9d058cc9da8c1ddc94d45c97a6ef24fb`; package checkout:`ed0145026559bad32293537a1472b2f37a89e2db`; base:`19c5396b4e896e6d81d6703f58dae76c389527d0`; Git tree:`f59342c5105b65af114fafabdacf04799867756a`.
- Оригинальный Actions artifact `release-install-44937440204-a1`, run44937440204, attempt1: ZIP SHA256 `0499714d94797e6667435e651824ac1bbf7fac783bc1690aa31b734ee8469c0b`; manifest SHA256 `d9ac879aa8fbb4463e04e95a03b89916fec19cfbce25a34f890f8204c4ea49e0`;1831файл.
- Его файловое дерево по тому же алгоритму:`3ead4a1083ea3f14201bafe1ee1efc1527227e9d3438afb1ee65a3bed9350ef0`.
- **Byte identity FAIL:**19различий, все проверенно объясняются LF/CRLF. Это CHANGED_PATHS/CHANGED_FILES в корне/release и `qa_source/run_checks.sh`. Различий PHP/JS/CSS/Mustache с этим ZIP не обнаружено. Нормализация EOL — отдельная диагностическая проверка, она не превращает raw FAIL в PASS.
- Сравнение с полным source main даёт77различий:58отсутствующих файлов исходного дерева относительно установочного состава +19EOL. Само отличие состава source/install не доказывает дефект; reference для доставки — manifest ZIP.

Квитанция #148 заявляет ZIP `e340ddb4…`, дерево `9b2dca77…`,21.11 22:12МСК. Полные hashes/тот ZIP/алгоритм квитанции не представлены в комментарии; **точная идентичность этому deployment ZIP NOT VERIFIED**. Не выдавать сравнение с Actions ZIP0499 за подтверждение e340. У #146 в описании/комментариях встречаются ad7ac56f…, b9f1faef… и8b3d8152… и два времени публикации; это разные этапы/пакеты, не единая идентичность. Нужна сводная непротиворечивая цепочка, а не выбор удобного комментария.

Прочитанная старая конфигурация оператора #120 содержит ZIP768efdd… и manifest86790bb…; текущие файлы ему НЕ соответствуют. Это ожидаемо для исторического receipt, **не доказательство отката к #120** и не свежая квитанция установки.

## 3. UX Acceptance

Эталон #122 head `a099e6a1bd671b284e33221f44851928eacf0d87`: просмотрены `A-conversation-1440.png` и `tablet-768-notifications.png`, прочитаны UX document и #145. Прототип содержит синтетические данные; численные значения его counters не являются ожидаемыми значениями реальной БД.

Владелец вошёл в test. Первая страница `/admin/index.php` показывала проверку выпуска с «Продолжить»; кнопка НЕ нажата. Наличие этой страницы само по себе не доказывает pending migration: прямой вход в центр с корректным company context работает.

Базовый URL test: https://test.bms24.online/local/kbacompany/communication_center.php . Company UUID выбран из доступного владельцу списка; персональные названия/сообщения не переписываются в этот отчёт. Скриншоты приватные, с реальными данными, в публичный Git их не включать.

| Сценарий | Статус | Наблюдение |
|---|---|---|
| Разговоры / Обсуждения / Уведомления | PASS ограниченный | все3раздела открываются; вкладки видны. Полная функциональность не принята |
| Default «Разговоры/Все» | PASS визуальный | явные активные раздел/отбор |
| Подписи RU | FAIL | `[[communicationux2_newconversation]]`, `chip_mentions`, `select_title/text`, notification labels и empty-search keys |
| Список обсуждений | FAIL | название, тип и автор/preview слипаются; см. D-UX02 |
| Поиск | PASS транспорт/FAIL empty UX | GET q=qa-no-match-20261122 даёт пустую выдачу, но заголовок/описание/сброс — rawkeys. Поиск по имени НЕ проверен |
| Фильтры | NOT VERIFIED полностью | Все/Непрочитанные/@ видны; точность состава выдачи относительно БД не сверена |
| Counters | NOT VERIFIED семантика | видны1/9/64; заголовок уведомлений22. Это может быть разными единицами/пагинацией, само расхождение не доказывает неверный подсчёт; нужны определения и DB reconciliation |
| 1440 / 768 | FAIL UX соответствия | screenshots показывают перечисленные дефекты на обеих ширинах. Pixel-perfect equality/ширина overflow метрикой не измерялись |
| Уведомления как отдельный экран | PASS структурный | без области переписки; но controls/labelsFAIL |
| Bulk read | действие НЕ выполнялось | видна кнопка с ключом mark_all; в прототипе функция помечена «предложение», прежнее решение18.11 не утверждало её. Требуется найти более позднее явное решение, не принимать наличие в макете за разрешение |
| Отправка и вложения | NOT VERIFIED | test содержит реальные восстановленные записи; отдельный disposable fixture/isolation не подтверждён, реальные записи не создавались |
| Панель участников / задачи / боевые планы | NOT VERIFIED runtime | не открывались конкретные треды/задачи, чтобы не менять read/audit state; source links не равны успешному сценарию |
| История, редактирование/удаление, скачивание | NOT VERIFIED runtime | нет write/destructive probes в реальных данных |

## 4. Security and ACL

Независимо выполнены `python -m unittest test_communication_ux_contract test_contract -v` на скачанном точном main: **42теста PASS**,0fail. Это unit/negative проверки валидатора на искусственных наблюдениях: пустые case lists, HTTP500/302, неверные маршруты/компании, redirects, csrf/double-submit evidence и прочее. **Это НЕ42Moodle-сценария и НЕ доказательство реального tenant isolation.** Лог сохранён.

Статический просмотр: `local/kbacompany/communication_center.php:14` содержит require_login;:41–44 ограничивает HTTP methods;:61–63 требуетPOST для action и require_sesskey. Это наличие контролей, не независимое доказательство правильности каждой authorization ветви.

| Проверка | Результат |
|---|---|
| A/B tenant isolation, участник/руководитель/посторонний | NOT VERIFIED runtime |
| Отзыв доступа к discussion | NOT VERIFIED runtime |
| Прямой URL чужого сообщения/файла | NOT VERIFIED runtime |
| CSRF/GET mutation rejection | source control present; runtime NOT VERIFIED |
| Сохранность message history | NOT VERIFIED runtime |
| Outbound deny/cron выключен на тестовом клоне | NOT VERIFIED: конфигурация сети test не исследована этим аудитом |

Наличие роли администратора в сессии владельца не является проверкой ограниченного сотрудника/руководителя. Требуются существующие безопасные тесты на точном пакете или отдельная синтетическая fixture с подтверждённым outbound deny. До этого нельзя ставить security PASS.

## 5. Release Conveyor Compliance

PR#144 MERGED, head `60db39ce7b5c99e1ec57f158bb7bb5d64282a1fa`. Изучены все4изменённых файла. Это не чисто документационный diff: есть `tools/check_reconcile_1879.py`; его scope прямо «source preservation only; not runtime dependency or tenant acceptance». Текст документа описывает принятие через merge; этот merge уже состоялся, поэтому старую формулировку «предложен» нельзя использовать как текущий статус PR. В этом аудите он НЕ редактировался.

| Условие | Предусмотрено документом | Независимая техническая оценка |
|---|---|---|
| CI PASS на exact SHA | §4/6 | Есть CI/Merge gate; связанный mandatory rehearsal148 FAIL. Production enforcement не подтверждён |
| Immutable ZIP на test | §4/5 | Требование есть; точный установленный test ZIP/manifest не снят: NOT VERIFIED |
| Owner acceptance | §5/6 | #145 ранее НЕ ПРИНЯТО; новый ownerPASS этого состава не найден. Read-only аудит не заменяет owner decision |
| Test/prod SHA256 equality | §4/6/7 | FAIL chain: заявленный e340 не равен скачанному0499;19raw runtime differences. ПричинаEOL установлена, происхождение e340 ещё нет |
| Backup/restore по риску | §3/5/6 | Требование есть; свежая полнота и restore здесь NOT VERIFIED |
| Защита от прямого SSH | Не реализована изменёнными4файлами | FAIL как технический барьер: существующий SSH выполняет Python; os.access(W_OK) true для module dir и communication_center.php. Запись НЕ пробовалась. Документ/Actions не лишают этого principal возможности обойти pipeline |

Квитанция #146 прямо сообщает установку до завершения полногоCI по решению владельца. Это факт заявления о разрешённом исключении, не доказательство злонамеренного обхода; независимые серверные логи той исторической операции не исследованы. Однако такой маршрут не может одновременно считаться технически неизбежным predeploy gate.

#148: [run44937440204](https://github.com/rimanfatykhov-arch/bms24/actions/runs/44937440204) — обе rehearsal jobs FAIL с **RuntimeError: package PR identity/base changed; re-evaluate integration** (`tools/acceptance/pr90/rehearsal.py:38–39`). Это первичный отказ provenance до полноценной репетиции, не подтверждённая ошибка схемы Moodle. [tiers44937440222](https://github.com/rimanfatykhov-arch/bms24/actions/runs/44937440222) — Merge gate/BROWSER success. Два красных Moodle jobs retained run44937440275 не объявляются «историческими» без сравнения идентификаторов ошибок: применимость NOT VERIFIED.

## 6. Defects P0/P1/P2/P3

P0 не подтверждены. Приоритеты ниже — аудиторские рекомендации, не разрешение на изменение серверов.

| ID | Приоритет | Дефект / ожидаемое / факт / воспроизведение |
|---|---|---|
| D-REL01 | P1 | Технический production gate обходится write-capable SSH. Ожидается контролируемый publish path; фактически arbitrary execution+effectivewrite. Read-only os.access повторяет проверку без записи. #144§6 — регламент, не блокировка |
| D-REL02 | P1 | Не замкнута artifact identity. Ожидается один точный ZIP/manifest и raw tree test=prod; CI0499, квитанцияe340,19EOLdifferences. Скачать artifact44937440204-a1, сверить все1831пути, повторить manifest/tree comparison |
| D-REL03 | P1, RESOLVED в #150 | #148 обе репетиции исторически FAIL, хотя receipt говорит о публикации и Merge gate зелёный. Воспроизведение не требует новой установки: открыть логи run44937440204; проверить строку38–39 provenance guard. Не отключать guard |
| D-UX01 | P1 | Сырые ключи на test вместо русских labels. Ожидаются «Новый разговор»,«@ Упоминания»,читаемое пустое состояние; фактически [[...]]. Открыть центр→вкладки; поиск qa-no-match-20261122. Screens center-1440/768,notifications/search-empty. RU source main содержит ключ newconversation:9871; причина cache/пакет/языковойкомпонент NOT VERIFIED, не назначать cache purge как доказанное исправление |
| D-UX02 | P2 | Слипание названия/типа/автора/preview в обсуждениях на768. Эталон — раздельная иерархия строки, реальные подписи слиты. Открыть Обсуждения на768; screenshot discussions-768. Проверить CSS scope и deployed asset identity, не переносить автоматически старую причину#148 |
| D-UX03 | P2 | Поиск/новый разговор не соответствуют controls прототипа: маленькое поле/сырой placeholder и текстовый summary вместо оформленной primary кнопки. Открыть пустой разговор на1440; center-1440. Header template main:8 включает bms24_comm_new; inspect CSS/translated labels до правки |
| D-GOV01 | P2 | Кнопка mark_all присутствует без найденного решения владельца на предложенную функцию. Notifications template:9. Найти последующее утверждение либо вынести решение владельцу; кнопку не нажимали. Не считать это доказанным отсутствием разрешения во всей истории |
| D-EVID01 | P2 | Публикационные комментарии используют сокращённые/разные hashes и разные алгоритмы tree, поэтому не дают воспроизводимой цепочки. Полная таблица hashes/time/operator/CI/test/prod нужна для каждого этапа146/148 |

## 7. Evidence: commit SHA, CI run, URL, screenshot

- [#122 prototype](https://github.com/rimanfatykhov-arch/bms24/pull/122), [#145 acceptance](https://github.com/rimanfatykhov-arch/bms24/issues/145), [#144 conveyor](https://github.com/rimanfatykhov-arch/bms24/pull/144).
- [#146 receipt1](https://github.com/rimanfatykhov-arch/bms24/pull/146#issuecomment-7059768806), [receipt2](https://github.com/rimanfatykhov-arch/bms24/pull/146#issuecomment-7062406186), [#148 receipt](https://github.com/rimanfatykhov-arch/bms24/pull/148#issuecomment-7067860250).
- Exact main/head/package identities — §2. [#148 package/rehearsals](https://github.com/rimanfatykhov-arch/bms24/actions/runs/44937440204), [Merge gate/BROWSER](https://github.com/rimanfatykhov-arch/bms24/actions/runs/44937440222).
- Локальный evidence root: `outputs/independent-qa-20261122/`: pr122/144/146/148.json, issue145.json, main.json, package148/, runtime-zip-compare.json, runtime-main-compare.json, rehearsal148.log, contract-tests.log, prototype PNGs. Эти приватные файлы не включать целиком в Git.
- SSH read-only evidence: `outputs/release103-operator/audit20261122{prod,receipts,db,authority}.log`. Содержат внутренние пути/hash inventories; не публиковать сырыми.
- Скриншоты: `center-1440.png`, `center-768.png`, `discussions-768.png`, `notifications-768.png`, `search-empty-768.png`, `test-admin-release.png`, `test-login.png` в evidence root. Доступны владельцу локально; могут содержать персональные данные, не предназначены для публичного PR. Каждый UX-дефект привязан к имени скриншота выше.
- Проверки отправки, файлов, CSRF, A/B runtime и ревокации не выданы за PASS. Новый browser console/network capture не собирался: NOT VERIFIED.

## 8. GO / HOLD recommendation

**HOLD:** закрытие #145, признание визуального соответствия прототипу и автоматического production-конвейера завершёнными. **GO:** DeepSeek исправляет конкретные дефекты отдельными законченными срезами, сохраняя обязательные gates. Сам факт работающего production не доказывает прохождение процесса; сам FAIL репетиции не требует необоснованного DB rollback.

Production данным аудитом не обновлялся; состояние cron/maintenance не менялось. Issue145 не закрыт. Merge и deployment не выполнялись. Этот отчёт не является owner acceptance или разрешением публикации.

## 9. Точные задания DeepSeek

1. **D-REL02/EVID01:** предоставить фактический неизменённый ZIPe340 с полным SHA256,manifest,sourceSHA,полным runtime inventory и алгоритмом tree; связать с установленным test. Объяснить19EOLразличий. Не пересобирать старый ZIP задним числом и не заменять receipt новым незаявленным пакетом.
2. **D-REL03 уже исправлен #150:** не повторять эту разработку. Сохранить точные head/base и ancestry guard; для нового продуктового кандидата использовать соответствующий новый пакет и результаты. Старые FAIL#148 не считать его PASS. Отдельно сравнить retained failures по точным testIDs с baseline, не по имени job.
3. **D-UX01:** read-only сверить язык/версию/файлы и реально подключённые assets test с manifest; установить причину rawkeys (частичная установка/кеш/контексткомпонента). Исправить в своей ветке, показать screenshots1440/768 без [[...]], затем независимый повтор. Не менять production во время диагностики.
4. **D-UX02/03:** восстановить вид списка/поле поиска/primary newconversation относительно #122, проверить CSS scope и реальный загруженный CSS. Сохранить E2/ACL/messages/files/R1R2. Не считать отсутствие выбранного треда само по себе дефектом.
5. Подготовить существующие синтетические роли A/B и набор disposable сообщений/файлов с подтверждённой изоляцией; выполнить реальные негативные случаи role/revocation/CSRF/GET/direct-fileURL и positive send/download/history на точном ZIP. Приложить результаты отдельно от42unitvalidatorPASS.
6. **D-REL01:** предложить владельцу минимальное ограничение publish principal (например, отдельный forced-command deploy entrypoint с manifest/attestation/owner-window проверкой, отдельный break-glass доступ с аудитом). Не менять SSH/ключи/rulesets самостоятельно. Негативный приёмочный критерий: неподписанный/непринятый ZIP или FAIL/отсутствующий CI/testreceipt не публикуется через штатного оператора; аварийный обход явно учтён, а не объявлен невозможным.
7. **D-GOV01:** установить статус ownerрешения по bulk read; старое «предложение» в прототипе не является разрешением. Не менять генерацию нулевых уведомлений/cron по собственной интерпретации.
8. После исправлений: один обновлённый exactcandidate, адресная повторная приёмка и конкретная заявка владельцу; #145 закрывает только владелец/уполномоченный по отдельному решению.
