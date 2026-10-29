<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Built-in methodology library for recurring task drafts. */
class recurring_task_library_service {
    public const CONTRACT_VERSION = '0.4.11.1';
    public const SOURCE_FILE_ID = '1c0kbItTDOZOV5PjzQ8anS5Nd9VTsEkNRZPRYRT0kcS4';
    public const SOURCE_REVISION = 'ANLCKQlxr5w2jWYGrO27v1WRSqvnGahBW6foRhEJ7TC1sUOmcE00OpvKfivG5EIEg7JBf5vfa2PG2R363np8ZQ';
    public const SOURCE_MODIFIED = '2025-10-06T06:33:41.812Z';
    public const SOURCE_TITLE = 'Копия Частовстречающиеся повторяющиеся задачи';
    public const SOURCE_EXEC_TITLE = 'Функции Исполнительного Директора';
    public const SOURCE_EXEC_SHA256 = '65d9e6b56f2324063f3b26d26e2da592b9fc2c86b7b1f61de2606f7c0f83cc3a';
    public const SOURCE_FIN_ACADEMY_TITLE = '[Бизнес Академия] Чек-лист финансового директора.xlsx';
    public const SOURCE_FIN_ACADEMY_SHA256 = 'ae97e1b74aa097dbf978b8359479afb86515774a5fc1f34bcfee1f7cd3eb1337';
    public const SOURCE_FIN_TITLE = 'Чек-лист финансового директора.xlsx';
    public const SOURCE_FIN_SHA256 = '0b7d3283d9dc03d09842c6ae6882163703fbffbbe06511f844cb1cd6a3c2f280';

    public static function sources(): array {
        return [
            'main' => [
                'type' => 'methodology_google_doc_snapshot',
                'label' => self::SOURCE_TITLE,
                'file_id' => self::SOURCE_FILE_ID,
                'revision' => self::SOURCE_REVISION,
                'modified' => self::SOURCE_MODIFIED,
                'hash' => '',
                'note' => '',
            ],
            'exec' => [
                'type' => 'uploaded_workbook_snapshot',
                'label' => self::SOURCE_EXEC_TITLE,
                'file_id' => '',
                'revision' => '',
                'modified' => '',
                'hash' => self::SOURCE_EXEC_SHA256,
                'note' => 'Excel checklist: blue cells are regular schedule; pink cells are event-driven and are not imported as recurring templates.',
            ],
            'finance_xlsx' => [
                'type' => 'uploaded_workbook_snapshot_pair',
                'label' => self::SOURCE_FIN_ACADEMY_TITLE . ' + ' . self::SOURCE_FIN_TITLE,
                'file_id' => '',
                'revision' => '',
                'modified' => '',
                'hash' => self::SOURCE_FIN_ACADEMY_SHA256 . ';' . self::SOURCE_FIN_SHA256,
                'note' => 'Both workbooks contain the same 45 functions and identical green schedule markings; scores/instruction state differ.',
            ],
        ];
    }

    public static function contract(): array {
        return [
            'contract' => 'bms_recurring_task_template_library', 'version' => self::CONTRACT_VERSION,
            'sources' => self::sources(), 'runtime_dependency' => false,
            'rules' => ['library_template_creates_company_scoped_draft', 'expected_product_requires_human_confirmation',
                'source_wording_is_preserved', 'ambiguous_source_frequency_requires_review',
                'exact_source_weekdays_are_preserved', 'event_driven_source_items_are_not_forced_into_recurrence',
                'library_template_can_create_one_off_canonical_task_for_battle_plan_without_creating_recurrence',
                'company_can_add_edit_archive_restore_categories_and_templates',
                'built_in_methodology_is_company_overlaid_not_destroyed'],
        ];
    }

    public static function builtin_categories(): array {
        return ['owner', 'executive', 'personnel', 'facility', 'sysadmin', 'marketing', 'sales', 'finance', 'procurement'];
    }

    public static function categories(string $companyuuid = ''): array {
        if ($companyuuid === '') { return self::builtin_categories(); }
        return recurring_task_library_admin_service::category_keys($companyuuid);
    }

    public static function category_options(string $companyuuid): array {
        return recurring_task_library_admin_service::category_options($companyuuid);
    }

    public static function builtin_templates(): array {
        $rows = [
            'owner' => [
                ['Провести аналитику конкурентов и записать задачи по улучшению продукта', 'monthly', '1 раз в месяц', false],
                ['Актуализировать цели компании', 'semiannual', '1 раз в шесть месяцев', false],
                ['Провести стратегическую сессию и декомпозировать цель с командой', 'semiannual', '1 раз в шесть месяцев', false],
                ['Актуализировать идеологию компании (миссию, путь, ценности, традиции)', 'semiannual', '1 раз в шесть месяцев', false],
                ['Провести организационный аудит бизнеса и поставить себе задачи по внедрению инструментов системного управления', 'quarterly', '1 раз в три месяца', false],
                ['Пообщаться с каждым ключевым сотрудником лично, замотивировать его, поднять дух, выслушать, дать рекомендации по улучшению работы. А если в процессе решения было выявлено, что сотрудник испорчен и ему нельзя помочь (сломлен духом, перекуплен, изменил цели, перегорел или выдохся), то принять решение по его замене.', 'review', 'В течение двух-трех недель', true],
                ['Проконтролировать финансы компании', 'weekly', 'Еженедельно', false],
                ['Анализ и утверждение недельного плана', 'review', '', true],
                ['Разработать и утвердить новые регламенты/инструкции', 'weekly', 'Еженедельно', false],
            ],
            'executive' => [
                ['Переместить задачи из стратегии в операционные планы', 'weekly', 'Еженедельно', false],
                ['Провести утренние планерки и вечерние собрания по отчётности', 'daily', 'Ежедневно', false],
                ['Провести собрание по распределению финансов', 'weekly', 'Еженедельно', false],
                ['Провести собрание по улучшениям в компании', 'biweekly', 'Раз в две недели', false],
                ['Рассмотреть операционные планы от руководителей (откорректировать и принять)', 'weekly', 'Еженедельно', false],
                ['Проконтролировать показатели эффективности и отреагировать на падение', 'daily', 'Ежедневно', false],
                ['Погрузиться в функцию построения компании(подбор, введение в должность, коммуникации, контроль соблюдения правил) и составить план развития', 'monthly', 'Раз в месяц', false],
                ['Погрузиться в коммерческую функцию (маркетинг, контент, продажи) и составить план развития', 'monthly', 'Раз в месяц', false],
                ['Погрузиться в функцию финансов (доходы, расходы, учет) и составить план развития', 'monthly', 'Раз в месяц', false],
                ['Проведение утренней координации с расстановкой задач на день всем директорам (согласно плана)', 'daily', 'Пн–Пт', false, 31, 0, 'exec', 30, '09:00'],
                ['Инспекция области найма, общение со стажерами', 'weekly', 'Пн/Ср/Пт', false, 21, 0, 'exec', 30, '09:30'],
                ['Присутствие на собеседованиях с соискателями (контроль проведения)', 'weekly', 'Вт/Чт', false, 10, 0, 'exec', 30, '10:00'],
                ['Контроль плана по доходу', 'weekly', 'Пн/Ср/Пт', false, 21, 0, 'exec', 30, '11:00'],
                ['Контроль плана по отработке денег', 'weekly', 'Вт/Чт', false, 10, 0, 'exec', 30, '11:00'],
                ['Координация с областью маркетинга (долгосрочные планы, сравнение плана и факта)', 'weekly', 'Пн', false, 1, 0, 'exec', 50, '14:00'],
                ['Координация с областью производства (долгосрочные планы, сравнение плана и факта)', 'weekly', 'Вт', false, 2, 0, 'exec', 50, '14:00'],
                ['Координация с областью контроля качества (долгосрочные планы, сравнение плана и факта)', 'weekly', 'Ср', false, 4, 0, 'exec', 50, '14:00'],
                ['Координация с областью расширения (долгосрочные планы, сравнение плана и факта)', 'weekly', 'Чт', false, 8, 0, 'exec', 50, '14:00'],
                ['Анализ запроса возвратных сумм и общение с ВИП клиентами', 'weekly', 'Пн', false, 1, 0, 'exec', 30, '15:00'],
                ['Анализ рекламаций и жалоб и предпринятых мер', 'weekly', 'Вт', false, 2, 0, 'exec', 50, '15:00'],
                ['Проверка показателей и командного духа сотрудников', 'weekly', 'Ср', false, 4, 0, 'exec', 30, '15:00'],
                ['Общение с сотрудниками (выборочно)', 'weekly', 'Чт', false, 8, 0, 'exec', 30, '15:00'],
                ['Текущие задачи', 'weekly', 'Пн/Вт/Ср/Чт', false, 15, 0, 'exec', 50, '16:00'],
                ['PR пост в соцсетях', 'weekly', 'Вт', false, 2, 0, 'exec', 20, '17:00'],
                ['Общение с владельцами компании по стратегическим задачам', 'weekly', 'Пн', false, 1, 0, 'exec', 50, '17:00'],
                ['Поддержка командного духа в чатах компании или в офисе', 'weekly', 'Вт/Чт', false, 10, 0, 'exec', 20, '17:30'],
                ['Обучение', 'weekly', 'Пн/Ср', false, 5, 0, 'exec', 50, '17:30'],
                ['Подготовка ежемесячного отчета учредителям', 'review', 'Месяц (день не указан)', true, 0, 0, 'exec', 200, '17:30'],
                ['Посещение PR мероприятий', 'weekly', 'Пт', false, 16, 0, 'exec', 50, '17:30'],
                ['Контроль за выполнением задач финколлегии с помощью вечерних отчетов', 'weekly', 'Пн/Вт/Ср/Чт', false, 15, 0, 'exec', 30, '18:00'],
                ['Написание личных заявок на процедуру финколлегии', 'weekly', 'Пт', false, 16, 0, 'exec', 20, '12:00'],
                ['Заполнение и анализ личных показателей', 'weekly', 'Пт', false, 16, 0, 'exec', 30, '13:00'],
                ['Составление предварительного плана', 'weekly', 'Пт', false, 16, 0, 'exec', 20, '13:30'],
                ['Проверка подготовки всех данных, сводок и отчетов для проведения финколлегии', 'weekly', 'Пт', false, 16, 0, 'exec', 50, '14:00'],
                ['Проведение финколлегии согласно проверочного списка', 'weekly', 'Пт', false, 16, 0, 'exec', 100, '14:30'],
                ['Согласование задачи и планов на неделю с членами финколлегии', 'weekly', 'Пт', false, 16, 0, 'exec', 30, '15:30'],
                ['Анализ достижения стратегичских задач с членами финколлегии', 'weekly', 'Пт', false, 16, 0, 'exec', 30, '16:00'],
                ['Контроль игр для сотрудников', 'weekly', 'Пт', false, 16, 0, 'exec', 30, '16:30'],
                ['Проведение еженедельного собрания персонала', 'weekly', 'Пт', false, 16, 0, 'exec', 50, '17:00'],
            ],
            'personnel' => [
                ['Актуализировать ОФС компании', 'daily', 'Ежедневно', false],
                ['Завести почты новым сотрудникам', 'daily', 'Ежедневно', false],
                ['Добавить нанятых сотрудников и удалить уволенных из чата', 'daily', 'Ежедневно', false],
                ['Выложить объявления о найме', 'weekly', 'Еженедельно', false],
                ['Обработать отклики от соискателей', 'daily', 'Ежедневно', false],
                ['Внести данные в отчёт по воронке найма', 'daily', 'Ежедневно', false],
                ['Актуализировать раздел “Вакансии” на сайте', 'weekly', 'Еженедельно', false],
            ],
            'facility' => [
                ['Убрать помещение “Название помещения”', 'daily', 'Ежедневно', false],
                ['Проверить туалетную бумагу', 'daily', 'Ежедневно', false],
                ['Проверить освежитель воздуха', 'daily', 'Ежедневно', false],
                ['Проверить наличие конфет для посетителей', 'daily', 'Ежедневно', false],
                ['Проверить наличие воды', 'daily', 'Ежедневно', false],
                ['Проверить наличие чая и кофе', 'daily', 'Ежедневно', false],
                ['Проверить наличие сахара', 'daily', 'Ежедневно', false],
                ['Проверить наличие чистящих и моющих средств', 'daily', 'Ежедневно', false],
                ['Выставить товар на витрины', 'daily', 'Ежедневно', false],
                ['Полить цветы', 'daily', 'Ежедневно', false],
                ['Протереть пыль в труднодоступных местах', 'daily', 'Ежедневно', false],
                ['Проверить чековую ленту', 'daily', 'Ежедневно', false],
                ['Поставить терминал для приема кредитных карт на зарядку', 'daily', 'Ежедневно', false],
                ['Поставить телефон на зарядку', 'daily', 'Ежедневно', false],
                ['Поставить магазин на охрану', 'daily', 'Ежедневно', false],
            ],
            'sysadmin' => [
                ['Продлить домен “Название домена”', 'annual', '1 раз в год', false],
                ['Оплатить хостинг', 'annual', '1 раз в год', false],
                ['Оплатить сервис “Название сервиса, который используете”', 'monthly', '1 раз в месяц', false],
                ['Проверить, работают ли формы заявок на сайте', 'daily', '1 раз в день', false],
                ['Проверить, что отображает Яндекс.Справочник при звонке с номера компании', 'monthly', '1 раз в месяц', false],
                ['Пополнить IP телефонию', 'weekly', '1 раз в неделю', false],
                ['Заплатить за интернет', 'monthly', '1 раз в месяц', false],
                ['Проверить наличие комплектующих для текущего ремонта техники', 'weekly', '1 раз в неделю', false],
                ['Проверить картриджи для принтеров', 'daily', '1 раз в день', false],
                ['Провести проверку сайта на вирусы', 'weekly', '1 раз в неделю', false],
            ],
            'marketing' => [
                ['Пополнить рекламные аккаунты', 'daily', 'Ежедневно', false],
                ['Заполнить отчет по источникам трафика', 'daily', 'Ежедневно', false],
                ['Провести анализ ценовой политики конкурентов', 'weekly', 'Еженедельно', false],
                ['Провести АБ-тест по рекламным каналам и подготовить отчет по ним', 'monthly', 'Ежемесячно', false],
                ['Ответить на сообщения в соцсетях', 'daily', 'Ежедневно', false],
                ['Ответить на комментарии в соцсетях', 'daily', 'Ежедневно', false],
                ['Провести опрос по NPS', 'weekly', 'Еженедельно', false],
                ['Проверить отзывы и ответить на них', 'daily', 'Ежедневно', false],
                ['Выложить сторис', 'daily', 'Ежедневно', false],
                ['Выложить пост в соцсети', 'daily', 'Ежедневно', false],
                ['Провести прямой эфир в Инстаграм', 'twicemonthly', '2 раза в месяц', false],
                ['Написать тексты на сайт', 'weekly', 'Еженедельно', false],
                ['Написать тексты для рассылок', 'weekly', 'Еженедельно', false],
                ['Собрать и заполнить аналитику', 'daily', 'Ежедневно', false],
                ['Улучшить конверсию сайта', 'weekly', 'Еженедельно', false],
                ['Увеличить кол-во целевых обращений в компанию', 'weekly', 'Еженедельно', false],
                ['Снизить стоимость целевого обращения', 'weekly', 'Еженедельно', false],
            ],
            'sales' => [
                ['Поставить план продаж', 'weekly', '1 раз в неделю', false],
                ['Составить план-факт продаж на текущую дату', 'daily', 'Ежедневно', false],
                ['Провести личную мотивационную беседу с каждым сотрудником', 'twiceweekly', '2 раза в неделю', false],
                ['Провести аудит воронки каждого сотрудника', 'twiceweekly', '2 раза в неделю', false],
                ['Провести планерку факт за вчера и план на сегодня', 'daily', 'Ежедневно', false],
                ['Провести тренинг по навыкам продаж', 'daily', 'Ежедневно', false],
                ['Улучшить инструменты для работы с воронкой продаж', 'monthly', '1 раз в месяц', false],
                ['Пересмотреть систему мотивации', 'quarterly', '1 раз в квартал', false],
                ['Отправить отчет в мессенджерах', 'daily', 'Ежедневно', false],
                ['Оценить качество звонков, выявить критичные ситуации и улучшить разговоры каждого сотрудника', 'daily', 'Ежедневно', false],
            ],
            'finance' => [
                ['Провести сверку остатков по счетам', 'daily', 'Ежедневно', false],
                ['Сформировать реестр платежей', 'daily', 'Ежедневно', false],
                ['Актуализировать отчет по дебиторской и кредиторской задолженностях', 'weekly', '1 раз в неделю', false],
                ['Провести инвентаризацию основных средств, оборудования и инвентаря', 'monthly', '1 в месяц', false],
                ['Свериться с поставщиками', 'quarterly', '1 раз в квартал', false],
                ['Сформировать и отправить руководителю отчет о движении денежных средств / отчет о доходах и расходах', 'daily', 'Ежедневно', false],
                ['Выверить остатки подотчетных средств, востребовать авансовые отчеты', 'weekly', '1 раз в неделю', false],
                ['Провести инвентаризацию договоров (наличие/ актуальность/ сроки действия и т. п.)', 'quarterly', '1 раз в квартал', false],
                ['Разработка финансовой модели распределения денег, соответсвующей операционной деятельности и планируемой прибыли', 'weekly', 'Чт', false, 8, 0, 'finance_xlsx', 100, ''],
                ['Контроль применения финансовой модели распределения денег', 'weekly', 'Чт', false, 8, 0, 'finance_xlsx', 100, ''],
                ['Рассчет точки безубыточности компании', 'weekly', 'Чт', false, 8, 0, 'finance_xlsx', 100, ''],
                ['Перерасчет точки безубыточности и коррекция финмодели', 'weekly', 'Чт', false, 8, 0, 'finance_xlsx', 100, ''],
                ['Контроль за движением денежных средств и сведением остатков', 'weekly', 'Чт', false, 8, 0, 'finance_xlsx', 100, ''],
                ['Контроль за договорными оплатами в срок', 'weekly', 'Чт', false, 8, 0, 'finance_xlsx', 100, ''],
                ['Создание фондов, с целью избежания кассовых разрывов', 'weekly', 'Чт', false, 8, 0, 'finance_xlsx', 100, ''],
                ['Контроль оплаты крупных обязательств, которые могут привести к неприятностям', 'weekly', 'Чт', false, 8, 0, 'finance_xlsx', 100, ''],
                ['Создание фондов, с целью сощдание подушки безопасности', 'weekly', 'Чт', false, 8, 0, 'finance_xlsx', 100, ''],
                ['Создание фондов, с целью выплаты дивидендов учредителям', 'weekly', 'Чт', false, 8, 0, 'finance_xlsx', 100, ''],
                ['Контроль начисления и своевременной выплаты дивидендов', 'weekly', 'Чт', false, 8, 0, 'finance_xlsx', 150, ''],
                ['Контроль подачи заявок, с обоснованием и данными по рентабельности расхода', 'weekly', 'Чт', false, 8, 0, 'finance_xlsx', 100, ''],
                ['Создание и поодержание системы обычных и депозитных  расчетных счетов для оптимального движения и хранения денег', 'weekly', 'Чт', false, 8, 0, 'finance_xlsx', 150, ''],
                ['Мониторинг банков, с целью сохранности денег в безопасности', 'weekly', 'Чт', false, 8, 0, 'finance_xlsx', 100, ''],
                ['Создание правил и бизнес-процессов по работе с дебиторской задолженностью', 'weekly', 'Чт', false, 8, 0, 'finance_xlsx', 100, ''],
                ['Создание правил и бизнес-процессов по работе с кредиторской задолженностью', 'weekly', 'Чт', false, 8, 0, 'finance_xlsx', 100, ''],
                ['Контроль соблюдения правил работы с дебиторской задолженностью', 'weekly', 'Чт', false, 8, 0, 'finance_xlsx', 100, ''],
                ['Контроль соблюдения правил работы с кредиторской задолженностью', 'weekly', 'Чт', false, 8, 0, 'finance_xlsx', 100, ''],
                ['Создание сводки по анализу рентабельности расходов', 'weekly', 'Чт', false, 8, 0, 'finance_xlsx', 100, ''],
                ['Внедрение и контроль работы с расходами, на основании сводки рентабельности расходов', 'weekly', 'Чт', false, 8, 0, 'finance_xlsx', 150, ''],
                ['Создание сводки по анализу происхождения дохода', 'weekly', 'Чт', false, 8, 0, 'finance_xlsx', 100, ''],
                ['Внедрение и контроль работы с доходом, на основании сводки происхождения доходов', 'weekly', 'Чт', false, 8, 0, 'finance_xlsx', 150, ''],
                ['Утверждение одобренных финансовой коллегией расходов', 'weekly', 'Чт', false, 8, 0, 'finance_xlsx', 100, ''],
                ['Контроль реализации утвержденных расходов', 'weekly', 'Чт', false, 8, 0, 'finance_xlsx', 100, ''],
                ['Перераспределение денег в фондах, согласно потребностям', 'weekly', 'Чт', false, 8, 0, 'finance_xlsx', 100, ''],
                ['Контроль суммы полученных денег, в соотношении с отработанными', 'weekly', 'Чт', false, 8, 0, 'finance_xlsx', 100, ''],
                ['Анализ производства в отношении отработки полученных денег (кол-во сотрудников, площадей, оборудования и других производственных мощностей)', 'weekly', 'Чт', false, 8, 0, 'finance_xlsx', 150, ''],
                ['Утверждение количества сотрудников в соотношении с точкой безубыточности и планами по развитию', 'weekly', 'Ср', false, 4, 0, 'finance_xlsx', 100, ''],
                ['Контроль количества работающих продавцов, с целью достижения точки безубыточности', 'weekly', 'Ср', false, 4, 0, 'finance_xlsx', 150, ''],
                ['Контроль работы с закупками, с целью обеспечения склада рентабельными позициями и предотвращение неликвида', 'weekly', 'Ср', false, 4, 0, 'finance_xlsx', 150, ''],
                ['Расчет и уверждение цен на товрары и услуги', 'weekly', 'Ср', false, 4, 0, 'finance_xlsx', 100, ''],
                ['Расчет и утверждение акций и скидок', 'weekly', 'Ср', false, 4, 0, 'finance_xlsx', 100, ''],
                ['Предложение и внедрение эффективных и мотивирующих систем начисления ФОТ', 'weekly', 'Ср', false, 4, 0, 'finance_xlsx', 100, ''],
                ['Анализ ведомости по зарплате и уровеню зарплат сотрудников', 'monthly', '1-я неделя месяца: Пн', false, 1, 1, 'finance_xlsx', 100, ''],
                ['Разарботка и создание правильного уровня потребности в доходе, с целью, чтобы компания развивалась на свои, а не на кредитные средства', 'weekly', 'Пн', false, 1, 0, 'finance_xlsx', 100, ''],
                ['Создание и ведение БДДС и БДР', 'weekly', 'Пн', false, 1, 0, 'finance_xlsx', 100, ''],
                ['Создание и ведение ОДДС и ОПиУ', 'weekly', 'Вт', false, 2, 0, 'finance_xlsx', 100, ''],
                ['Ежемесячный план-фактный анализ управленческой отчетности с выводами и предложениами', 'review', 'Вт', true, 2, 0, 'finance_xlsx', 100, ''],
                ['Создание и ведение управленческого баланса, отслеживание финансового состояния компании', 'weekly', 'Вт', false, 2, 0, 'finance_xlsx', 100, ''],
                ['Создание и контроль соблюдения всех финансовых регламентов', 'weekly', 'Вт', false, 2, 0, 'finance_xlsx', 100, ''],
                ['Своевременное ведение всех финансовых метрик', 'weekly', 'Вт', false, 2, 0, 'finance_xlsx', 100, ''],
                ['Полный контроль финансовой ситуации', 'monthly', '1-я неделя месяца: Пн', false, 1, 1, 'finance_xlsx', 150, ''],
                ['Работа с советом руководителей, согласно проверочного списка', 'weekly', 'Пн', false, 1, 0, 'finance_xlsx', 100, ''],
                ['Создание ежемесячной системы отчетво для владельца', 'review', 'Чт', true, 8, 0, 'finance_xlsx', 150, ''],
                ['Ежемесячная работа с владельцем по полному пониманию системы отчетов и финансовой ситуации', 'review', 'Пт', true, 16, 0, 'finance_xlsx', 150, ''],
            ],
            'procurement' => [
                ['Проанализировать заказы и составить план закупок и доставок', 'daily', 'Ежедневно', false],
                ['Проанализировать 5 новых поставщиков по каждому наименованию закупок на день и найти лучшее предложение', 'daily', 'Ежедневно', false],
                ['Заполнить отчет по доставкам за день', 'daily', 'Ежедневно', false],
                ['Запросить актуальные прайсы у поставщиков по товарам на реализации', 'daily', 'Ежедневно', false],
                ['Провести инвентаризацию на складе', 'monthly', '1 раз в месяц', false],
                ['Проверить и устранить дефицит позиций с неснижаемыми остатками (стоковые)', 'daily', 'Ежедневно', false],
                ['Передать оригиналы документов по доставленным заказам в бухгалтерию', 'daily', 'Ежедневно', false],
                ['Пересмотреть и провести расчет нормативов производственных запасов', 'monthly', '1 раз в месяц', false],
                ['Сформировать и отправить руководителю отчет об оборачиваемости товаров на реализацию', 'weekly', '1 раз в неделю', false],
            ],
        ];
        $out = [];
        foreach ($rows as $category => $items) {
            $index = 0;
            foreach ($items as $item) {
                $index++;
                $key = 'rtpl_' . $category . '_' . str_pad((string)$index, 2, '0', STR_PAD_LEFT);
                $sourcekey = isset($item[6]) ? (string)$item[6] : 'main';
                $sources = self::sources();
                $source = $sources[$sourcekey] ?? $sources['main'];
                $out[$key] = [
                    'key' => $key, 'source_uuid' => self::template_source_uuid($key), 'revision' => 1,
                    'category' => $category, 'title' => $item[0],
                    'cadence' => $item[1], 'source_cadence' => $item[2], 'requires_review' => $item[3],
                    'weekdaymask' => isset($item[4]) ? (int)$item[4] : 0,
                    'monthweek' => isset($item[5]) ? (int)$item[5] : 0,
                    'source_key' => $sourcekey, 'source_type' => (string)$source['type'],
                    'source_label' => (string)$source['label'], 'source_hash' => (string)$source['hash'],
                    'source_file_id' => (string)$source['file_id'], 'source_revision' => (string)$source['revision'],
                    'source_score' => isset($item[7]) ? (int)$item[7] : 0,
                    'source_time' => isset($item[8]) ? (string)$item[8] : '',
                ];
            }
        }
        return $out;
    }

    public static function templates(string $companyuuid = ''): array {
        $builtins = self::builtin_templates();
        if ($companyuuid === '') { return $builtins; }
        return recurring_task_library_admin_service::merged_templates($companyuuid, $builtins);
    }

    /** Stable UUID used when a library template becomes provenance of a one-off canonical task. */
    public static function template_source_uuid(string $key): string {
        $hex = substr(hash('sha256', 'bms-task-template:' . $key), 0, 32);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) .
            '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }

    public static function template(string $key, string $companyuuid = ''): ?array {
        $templates = self::templates($companyuuid);
        return $templates[$key] ?? null;
    }

    public static function template_by_source_uuid(string $uuid, string $companyuuid = ''): ?array {
        foreach (self::templates($companyuuid) as $template) {
            if ((string)$template['source_uuid'] === $uuid) { return $template; }
        }
        if ($companyuuid !== '') {
            return recurring_task_library_admin_service::template_by_source_uuid($companyuuid, $uuid);
        }
        return null;
    }
}
