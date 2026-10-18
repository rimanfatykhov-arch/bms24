# PROJECT EXECUTION — 0.9.0.2

## Единые точки работы

Стратегия/Административная шкала: замысел и плановые объекты.
Управленческие циклы: периоды и контрольные точки.
Проект → Исполнение: read-only связи между замыслом и работой.
Tasks/Battle Plans/Meetings/Indicators: существующие канонические владельцы данных.

## Read pipeline

`administrative_scale.php` вызывает `administrative_cycle_service::project_execution`
только для доступной карточки типа project и recordtab=execution. Сервис повторно
получает canonical scale context и разрешает UUID. Task/Meeting selectors получают
company UUID и actor, применяют company/object/participant predicates в SQL.
UI получает готовую разрешённую модель и не обращается к БД.

Tasks: явный contextkind=project/contextuuid либо точная исходная adminitem task revision
с parentuuid проекта либо source decision доступной project meeting. Не manager получает
assignee/creator/reviewer/observer либо canonical plan participant predicate в SQL.
Meetings: company UUID + sourcekind admin_project + source UUID + EXISTS active meetpart.
DTO проектных встреч не извлекает вложенные задачи: task permissions проверяются отдельно.
Battle Plans: company-scoped DISTINCT references и прежние owner/reviewer/observer или
management rules. Внешние task providers не превращаются в канонические BMS задачи.

## Сохранение истории

Просмотр и повторный просмотр не пишут в БД. Канонические UUID/revisions, результаты,
статусы, сроки и история не копируются/не изменяются. Старый service context оставлен
для совместимости; его устаревший самостоятельный редактор больше недоступен через UI.
Existing project_source/meeting revision validation не менялись.

## Доступ и ограничения

Ссылка/членство/назначение куратора не создаёт новые права. Проверки исходных доменов
сохраняются. Компания не определяется по имени или external ID. Подмена UUID не должна
раскрывать другие компании/проекты. Права задач и встреч независимы; роль manager не
заменяет участие во встрече. Отсутствие видимых данных объяснено в интерфейсе.

## Legacy URL contract

GET/HEAD uuid → projects list; uuid+project → project detail execution;
uuid+edituuid → canonical edit after permission check. При одновременной передаче
edituuid/project сохраняется явный edit intent; edituuid имеет приоритет.
Неверная цель выдаёт ошибку, не подменяется другой. Любой метод кроме GET/HEAD
отклоняется без переноса action/POST payload и без записи.
