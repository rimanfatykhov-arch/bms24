# Коммуникационный центр112 поверх опубликованной сводки113

Baseline production:113 `5924bc074c2a34d485b18a4fba4f7b7aa9534358`, ZIP073ef79c05e3…,
runtimeb4d050b4…; owner production PASS, RESULTPUBLISHED/DONEsuccess15.11.2026 22:22:50МСК.
Источник Claude112:5b5d839af4eb682cfb76755799bb320292b0f1e6, уже содержит этот baseline.
Рабочая ветка Claude не редактируется. Кандидат оператора — release/communication-center-after113.

Состав: единое task discussion для читателей канонической задачи, read-only события из истории
(без opened в обсуждении), reply/quote/participant mentions, единственный личный диалог,
управление группой, фильтры. Личная сводка113, история открытия110, edit/delete103,
несколько вложений/меню102 сохранены. Нет изменений DB/version/Core/Corporate или новых capabilities.
Task-viewer ACL — ранее утверждённое изменение112; это FULL по действующей политике, не FAST.

## Подтверждённый блокер и исправление

На5b5d839 прежний validator принимал negative POST при любом http!=303. Независимые12 мутаций
(500/302/None × join/non-owner-remove/forged-sesskey/leave-task) давали ложный PASS всех8 новых checks.
Это доказательство валидатора на fixtures, не утверждение об ошибке работающего Moodle.

В этом release-кандидате:

- Producer сохраняет raw HTTP code, Location, response URL, SHA256 body и текст именно error blocks,
  не полный HTML с sesskey/credentials. Redirects не следуются, адрес перед API ограничен loopback.
- Validator требует ожидаемую страницу центра, отсутствие redirect, допустимый HTTP200/403/404,
  непустое body evidence и соответствующий штатный отказ permission/company/state/invalidsesskey.
  Canonical strings берутся из установленного Moodle через CI-only fixture helper.
  Статус200 сам по себе не означает отказ: без ожидаемой error block проверки FAIL.
- HTTP500/502, redirect302/303/307, missing/None/bool status, неверный origin/location,
  пустой/посторонний error block или отсутствующее evidence дают FAIL.
- Состояние участников всё ещё обязательно проверяется; ACL/file/revocation проверки не исключены.
- Positive form actions используют видимые кнопки с hit testing: containing details открываются
  обычным кликом/Enter, затем submit нажимается мышью/клавиатурой, без requestSubmit обхода UI.

Локально contract33 normal и optimized Python, source-preservation8 PASS. Все64 required names сохранены.
Runtime, полный CI, новый CI ZIP/manifest и owner preview требуют собственных доказательств этого head.
Первый runtime прогон e105af4 обнаружил ошибку порядка раскрытия вложенных details: XPath locator
возвращает document order, обратный обход пытался нажать невидимый внутренний summary. Исправлено
на outer→inner через реальные click/Enter; force/requestSubmit не добавляются. Успешные direct POST
redirects дополнительно ограничены канонической страницей/тем же thread; external/login redirects FAIL.
Незавершённый browser case сохраняется только для диагностики, не засчитывается полной матрицей.
Этот follow-up меняет только тестовую обвязку, продуктовые байты e105 не меняются.
Старые PASS112/113 не выдаются за новый release PASS. Feature112 package/rehearsal skip не засчитывается:
release/* выполняет действующую сборку и обе репетиции. Policy/rulesets/required checks не меняются.

## Порядок приёмки и публикации

1. Exact head/tree и исходный CI ZIP/manifest; FULL, STANDARD, BROWSER64 обе СУБД, Merge gate,
   новая release rehearsal MariaDB/Percona. Все красные jobs разбираются по действующей политике.
2. Тот же существующий приватный синтетический dev: один оператор, outbound deny, cron/интеграции off,
   CLI от web UID33. Без новой среды и без production-данных.
3. Реальные новые сценарии центра (executor/viewer/denied/revoked, файлы, reply/mention,
   direct uniqueness, remove/leave/owner-transfer, filters) и preservation сводки/истории/сообщений.
4. Один фиксированный пакет остаётся на dev для владельца. Не заменять во время просмотра.
5. Production только после просмотра и отдельного «публикуй» конкретному ZIP в актуальном окне.
   Baseline должен быть113 или явно пересогласованный последующий. Старый пакет112 поверх113 нельзя.
   Существующая проверенная копия на тесте используется по решению владельца; новый full backup не
   вводится автоматически. Совместимый откат — код113+purge, не БД/moodledata поверх новых записей.

Production/staging, рабочие stage/test, backup/ключи, merge и GitHubRelease здесь не изменяются.
