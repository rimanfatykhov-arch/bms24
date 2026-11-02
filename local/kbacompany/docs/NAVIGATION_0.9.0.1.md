# NAVIGATION 0.9.0.1 — BMS-owned presentation contract

## Верхнее меню управления

| Группа | Подразделы | Canonical controller |
|---|---|---|
| Обзор | Обзор | management.php |
| Планирование | Управленческая неделя; Боевой план; Управленческие циклы; Исполнение и встречи | battle_plan_overview.php; battle_plan.php; management_cycles.php; administrative_cycle.php |
| Показатели | Показатели; Исключения | indicators.php; indicators.php?tab=exceptions |
| Решения | Контур решений; Управленческий разбор; История решений; Повторяющиеся проблемы | management_loop.php; management_review.php; management_history.php; management_patterns.php |
| Рабочие места | Мои решения; Рабочее место куратора | owner_workspace.php; curator_workspace.php |

Фактические подписи дочерних разделов получаются из существующих RU/EN ключей.
Меню первого уровня открывает первый доступный подраздел; вторая строка всегда
показывает подразделы текущей группы. Обзор не дублируется во второй строке.
Управленческие ссылки скрываются при отсутствии существующего can_manage_management;
видимость ссылки сама по себе никогда не даёт права на данные или запись.

## Боковое меню

Четыре существующие группы workspace / architecture / people / management и utility Settings.
Дневная работа: рабочий стол, задачи, Боевой план, коммуникации.
Архитектура: существующий центр, собственник, философия, рынок, бизнес-модель,
финансовая модель, стратегия, административная шкала (с прежними permission gates).
Люди: существующая последовательность без изменения.
Управление: обзор, периоды, исполнение/встречи, показатели, документы, политики, отклонения.

Route keys сохраняются; products по-прежнему означает существующий indicators route.
managementcycles — добавленная ссылка на уже существующий management_cycles.php, не новый endpoint.
`label` доменной карточки сохраняется; `sidebar_label` меняет только компактную подпись.
`sidebar_active` отделяет подсветку видимого родителя от `active` в дереве архитектуры.
Данные меню продолжает поставлять local_kbacompany. Theme получает прежний формат groups/items.

## Безопасность и ограничения

Новых SQL, endpoint, таблиц, capabilities, background jobs или авторизационных bypass нет.
Публичный pure helper management_nav_model получает уже вычисленный canmanage и
строит только UI. Он не является разрешающим domain API.
URL формируется из фиксированного контроллера, Company UUID и разрешённых параметров;
сессии, action, POST-данные, произвольный redirect и чужие object UUID не копируются.

## Технические источники

W3C WAI ARIA26: https://www.w3.org/WAI/WCAG21/Techniques/aria/ARIA26
(aria-current для текущей ссылки; не интерактивные role=tab).
Moodle version.php: https://moodledev.io/docs/5.0/apis/commonfiles/version.php
(общий контракт поля plugin version; target проекта остаётся 4.1.22).

Изображения NAVIGATION_* — локальные DOM fixtures с тестовой внешней оболочкой,
а не снимки действующего сайта. Полная установленная KBA Theme в окружении отсутствует;
публичная попытка чтения страницы сайта завершилась ошибкой DNS. Live-верификация не заявляется.
