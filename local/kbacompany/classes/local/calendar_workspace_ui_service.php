<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** BMS UI renderer for Moodle Calendar projection and controlled manual-event CRUD. */
class calendar_workspace_ui_service {
    /**
     * Calendar UI text with a runtime fallback.
     *
     * Moodle can keep the component string map in cache after a plugin archive is
     * replaced. In that state newly added keys render as [[key]] until caches are
     * purged. Calendar is an additive workspace, so keep it usable immediately
     * after deployment while the normal language strings remain canonical.
     */
    public static function text(string $key, $a = null): string {
        $manager = get_string_manager();
        if ($manager->string_exists($key, 'local_kbacompany')) {
            $value = get_string($key, 'local_kbacompany', $a);
            if (strpos($value, '[[') !== 0) {
                return $value;
            }
        }

        $ru = [
            'bmscalendar_title' => 'Календарь',
            'bmscalendar_intro' => 'Единый управленческий календарь BMS24: обучение, задачи, встречи, Боевой план и контрольные точки.',
            'bmscalendar_boundary_notice' => 'Календарь объединяет read-only проекции BMS и обучения. Источники истины не меняются: задачи, встречи, Боевой план и управленческие циклы остаются в своих разделах, учебные события — в календаре обучения.',
            'bmscalendar_open_moodle' => 'Открыть полный календарь обучения',
            'bmscalendar_events' => 'События',
            'bmscalendar_events_caption' => 'в текущем представлении',
            'bmscalendar_courses' => 'Курсы',
            'bmscalendar_courses_caption' => 'в текущем режиме',
            'bmscalendar_upcoming' => 'Ближайшие',
            'bmscalendar_upcoming_caption' => 'предстоящие события в текущем представлении',
            'bmscalendar_prev' => 'Предыдущий',
            'bmscalendar_next' => 'Следующий',
            'bmscalendar_today' => 'Сегодня',
            'bmscalendar_no_courses' => 'В текущем режиме нет доступных учебных курсов.',
            'bmscalendar_upcoming_title' => 'Ближайшие события',
            'bmscalendar_no_upcoming' => 'В выбранном месяце больше нет предстоящих событий.',
            'bmscalendar_mon' => 'Пн',
            'bmscalendar_tue' => 'Вт',
            'bmscalendar_wed' => 'Ср',
            'bmscalendar_thu' => 'Чт',
            'bmscalendar_fri' => 'Пт',
            'bmscalendar_sat' => 'Сб',
            'bmscalendar_sun' => 'Вс',
            'bmscalendar_scope_my' => 'Мой календарь',
            'bmscalendar_scope_company' => 'Компания',
            'bmscalendar_scope_my_help' => 'Ваши личные, курсовые, групповые и общесайтовые учебные события — в пределах ваших прав доступа.',
            'bmscalendar_scope_company_help' => 'События только из курсов, явно связанных с текущей компанией.',
            'bmscalendar_source_personal' => 'Личное событие',
            'bmscalendar_source_site' => 'Общее событие обучения',
            'bmscalendar_source_group' => 'Событие группы',
            'bmscalendar_source_company' => 'Компания',
            'bmscalendar_source_moodle' => 'Обучение',
            'bmscalendar_add' => 'Добавить событие',
            'bmscalendar_edit' => 'Изменить',
            'bmscalendar_delete' => 'Удалить',
            'bmscalendar_editor_new' => 'Новое событие',
            'bmscalendar_editor_edit' => 'Редактирование события',
            'bmscalendar_field_name' => 'Название',
            'bmscalendar_field_description' => 'Описание',
            'bmscalendar_field_start' => 'Дата и время',
            'bmscalendar_field_duration' => 'Длительность, минут',
            'bmscalendar_repeat' => 'Повторять',
            'bmscalendar_repeat_none' => 'Не повторять',
            'bmscalendar_repeat_weekly' => 'Еженедельно',
            'bmscalendar_repeat_monthly' => 'Ежемесячно',
            'bmscalendar_repeat_weekdays' => 'По выбранным дням недели',
            'bmscalendar_repeat_count' => 'Количество событий',
            'bmscalendar_repeat_days' => 'Дни недели',
            'bmscalendar_repeat_note' => 'Серия создаётся как отдельные обычные события. Массовое изменение всей серии пока не выполняется.',
            'bmscalendar_field_target' => 'Календарь / курс',
            'bmscalendar_target_personal' => 'Личный календарь',
            'bmscalendar_save' => 'Сохранить',
            'bmscalendar_cancel' => 'Отмена',
            'bmscalendar_saved' => 'Событие сохранено.',
            'bmscalendar_deleted' => 'Событие удалено.',
            'bmscalendar_delete_confirm' => 'Удалить это событие?',
            'bmscalendar_readonly_generated' => 'События заданий, тестов, вебинаров и других модулей изменяются в их источнике.',
            'bmscalendar_no_create_targets' => 'Нет доступного календаря, в котором у вас есть право создавать события.',
            'bmscalendar_error_name' => 'Укажите название события.',
            'bmscalendar_error_name_long' => 'Название события слишком длинное.',
            'bmscalendar_error_date' => 'Укажите корректные дату и время.',

            'bmscalendar_filter_search' => 'Поиск по событиям',
            'bmscalendar_filter_kind' => 'Тип события',
            'bmscalendar_filter_all' => 'Все события',
            'bmscalendar_filter_manual' => 'Созданные вручную',
            'bmscalendar_filter_generated' => 'Из обучения',
            'bmscalendar_filter_apply' => 'Применить',
            'bmscalendar_filter_reset' => 'Сбросить',
            'bmscalendar_kind_manual' => 'Ручное событие',
            'bmscalendar_kind_generated' => 'Событие обучения',
            'bmscalendar_event_details' => 'Карточка события',
            'bmscalendar_open_source' => 'Открыть в обучении',
            'bmscalendar_detail_source' => 'Источник',
            'bmscalendar_detail_type' => 'Тип',
            'bmscalendar_detail_when' => 'Когда',
            'bmscalendar_detail_duration' => 'Длительность',
            'bmscalendar_detail_description' => 'Описание',
            'bmscalendar_no_description' => 'Описание не указано.',
            'bmscalendar_close' => 'Закрыть',
            'bmscalendar_add_on_day' => 'Добавить событие на этот день',
            'bmscalendar_duration_none' => 'Без длительности',
            'bmscalendar_minutes_short' => 'мин',
            'bmscalendar_hours_short' => 'ч',
            'bmscalendar_source_filter' => 'Источник',
            'bmscalendar_source_all' => 'Все источники',
            'bmscalendar_source_task' => 'Задачи',
            'bmscalendar_source_meeting' => 'Встречи',
            'bmscalendar_source_battleplan' => 'Боевой план',
            'bmscalendar_source_checkpoint' => 'Контрольные точки',
            'bmscalendar_source_cycle' => 'Управленческие циклы',
            'bmscalendar_kind_cycle' => 'Управленческий цикл',
            'bmscalendar_cycles_title' => 'Управленческие циклы периода',
            'bmscalendar_cycles_intro' => 'Неделя, месяц, квартал и год отображаются как единая иерархия управленческого ритма.',
            'bmscalendar_cycles_empty' => 'В выбранном периоде нет управленческих циклов.',
            'bmscalendar_rhythm_title' => 'Регулярный менеджмент',
            'bmscalendar_rhythm_intro' => 'Запуск периода → планёрки → контроль исполнения → закрытие. Все действия открывают канонический раздел и повторно проверяют права.',
            'bmscalendar_rhythm_week' => 'Управленческая неделя',
            'bmscalendar_rhythm_state' => 'Готовность недели',
            'bmscalendar_rhythm_missing' => 'Не запущено планов',
            'bmscalendar_rhythm_blocked' => 'Заблокировано планов',
            'bmscalendar_rhythm_overdue' => 'Просрочено задач',
            'bmscalendar_rhythm_decisions' => 'Требуют решения',
            'bmscalendar_rhythm_launch' => 'Запустить период',
            'bmscalendar_rhythm_meetings' => 'Планёрки',
            'bmscalendar_rhythm_control' => 'Контроль исполнения',
            'bmscalendar_rhythm_close' => 'Закрыть период',
            'bmscalendar_rhythm_readiness' => 'Проверить готовность',
            'bmscalendar_rhythm_acceptance' => 'Приёмка недели',
            'bmscalendar_rhythm_start_cycle' => 'Начать цикл',
            'bmscalendar_rhythm_close_cycle' => 'Закрыть цикл',
            'bmscalendar_cycle_period' => 'Период',
            'bmscalendar_kind_task' => 'Задача BMS',
            'bmscalendar_kind_meeting' => 'Встреча BMS',
            'bmscalendar_kind_battleplan' => 'Боевой план BMS',
            'bmscalendar_kind_checkpoint' => 'Контрольная точка',
            'bmscalendar_task_due' => 'Срок',
            'bmscalendar_task_start' => 'Старт',
            'bmscalendar_battleplan_prefix' => 'Боевой план',
            'bmscalendar_all_day' => 'В течение дня',
            'bmscalendar_bms_events' => 'BMS события',
            'bmscalendar_bms_events_caption' => 'задачи, встречи, планы и контрольные точки',
            'bmscalendar_moodle_events' => 'Обучение',
            'bmscalendar_moodle_events_caption' => 'учебные и личные события',
            'bmscalendar_open_bms_source' => 'Открыть объект BMS',
            'bmscalendar_view_month' => 'Месяц',
            'bmscalendar_view_week' => 'Неделя',
            'bmscalendar_view_day' => 'День',
            'bmscalendar_view_analytics' => 'Аналитика',
            'bmscalendar_analytics_title' => 'Календарная аналитика',
            'bmscalendar_analytics_intro' => 'Read-only анализ выбранного месяца по уже доступным вам событиям. Показатели не сохраняются и не являются оценкой сотрудника.',
            'bmscalendar_analytics_meeting_hours' => 'Время во встречах',
            'bmscalendar_analytics_meeting_density' => 'Плотность встреч',
            'bmscalendar_analytics_focus' => 'Потенциал фокус-времени',
            'bmscalendar_analytics_overdue' => 'Просроченные задачи',
            'bmscalendar_analytics_heavy_days' => 'Дни с высокой плотностью встреч',
            'bmscalendar_analytics_conflicts' => 'Дни с пересечениями',
            'bmscalendar_analytics_regular' => 'Сигналы регулярного менеджмента',
            'bmscalendar_analytics_team' => 'Загрузка команды',
            'bmscalendar_analytics_team_intro' => 'Сигнал перегруза: 75% и более рабочего окна занято событиями с длительностью либо есть временные пересечения.',
            'bmscalendar_analytics_load' => 'Загрузка',
            'bmscalendar_analytics_warning' => 'Требует внимания',
            'bmscalendar_analytics_ok' => 'В пределах ориентира',
            'bmscalendar_analytics_workdays' => 'рабочих дней',
            'bmscalendar_analytics_meetings' => 'встреч',
            'bmscalendar_analytics_hours' => 'ч',
            'bmscalendar_drag_hint' => 'Ручные события обучения можно перетаскивать на другой день. Время события сохраняется.',
            'bmscalendar_moved' => 'Событие перенесено.',
            'bmscalendar_error_move_date' => 'Не удалось определить новую дату события.',
            'bmscalendar_move_confirm' => 'Перенести событие на выбранный день?',
            'bmscalendar_day_busy' => 'Занято времени',
            'bmscalendar_day_conflicts' => 'События с пересечениями',
            'bmscalendar_add_at_time' => 'Добавить событие на это время',
            'bmscalendar_conflict_badge' => 'Пересечение',
            'bmscalendar_conflict_notice' => 'В расписании есть пересекающиеся по времени события. Проверьте их перед подтверждением планов.',
            'bmscalendar_workload_title' => 'Загрузка',
            'bmscalendar_workload_team' => 'Загрузка команды',
            'bmscalendar_workload_me' => 'Моя загрузка',
            'bmscalendar_employee_filter' => 'Сотрудник',
            'bmscalendar_employee_all' => 'Вся команда',
            'bmscalendar_workload_events' => 'событий',
            'bmscalendar_workload_tasks' => 'задач',
            'bmscalendar_workload_meetings' => 'встреч',
            'bmscalendar_workload_busy' => 'занято',
            'bmscalendar_workload_conflicts' => 'пересечений',
            'bmscalendar_workload_privacy' => 'Командная загрузка использует только BMS-объекты, которые вам уже разрешено видеть. Личные учебные календари других сотрудников не читаются.',
            'bmscalendar_finder_title' => 'Подбор времени встречи',
            'bmscalendar_finder_intro' => 'Выберите участников, дату и длительность. BMS24 покажет общие свободные окна по доступной вам управленческой загрузке.',
            'bmscalendar_finder_date' => 'Дата',
            'bmscalendar_finder_duration' => 'Длительность',
            'bmscalendar_finder_members' => 'Участники',
            'bmscalendar_finder_find' => 'Найти время',
            'bmscalendar_finder_slots' => 'Подходящие окна',
            'bmscalendar_finder_none' => 'В рабочем окне 09:00–18:00 подходящих интервалов не найдено.',
            'bmscalendar_finder_create' => 'Создать встречу',
            'bmscalendar_finder_privacy' => 'Подбор использует только доступные вам BMS-события. Личные учебные календари других сотрудников не читаются, поэтому результат является рекомендацией, а не гарантией полной доступности.',
            'bmscalendar_finder_workhours' => 'Рабочее окно',
            'bmscalendar_planmeet_title' => 'Планёрка: повестка → решения → задачи',
            'bmscalendar_planmeet_intro' => 'Ближайшие доступные вам встречи показаны как единый управленческий цикл исполнения. Все изменения выполняются в канонической карточке встречи.',
            'bmscalendar_planmeet_empty' => 'В ближайшие 7 дней нет запланированных встреч.',
            'bmscalendar_planmeet_create' => 'Создать планёрку',
            'bmscalendar_planmeet_agenda' => 'Повестка',
            'bmscalendar_planmeet_decisions' => 'Решения',
            'bmscalendar_planmeet_tasks' => 'Задачи',
            'bmscalendar_planmeet_open' => 'Открыть планёрку',
            'bmscalendar_planmeet_close' => 'К закрытию',
            'calendar_notify_title' => 'Уведомления календаря',
        ];
        $en = [
            'bmscalendar_title' => 'Calendar',
            'bmscalendar_intro' => 'A unified BMS24 management calendar: learning, tasks, meetings, Battle Plans and checkpoints.',
            'bmscalendar_boundary_notice' => 'The calendar combines read-only BMS and learning projections. Canonical sources remain unchanged: tasks, meetings, Battle Plans and management cycles stay in their own workspaces; learning events stay in the learning calendar.',
            'bmscalendar_open_moodle' => 'Open full learning calendar',
            'bmscalendar_events' => 'Events',
            'bmscalendar_events_caption' => 'in the current view',
            'bmscalendar_courses' => 'Courses',
            'bmscalendar_courses_caption' => 'in the current scope',
            'bmscalendar_upcoming' => 'Upcoming',
            'bmscalendar_upcoming_caption' => 'future events in the current view',
            'bmscalendar_prev' => 'Previous',
            'bmscalendar_next' => 'Next',
            'bmscalendar_today' => 'Today',
            'bmscalendar_no_courses' => 'There are no accessible learning courses in the current scope.',
            'bmscalendar_upcoming_title' => 'Upcoming events',
            'bmscalendar_no_upcoming' => 'There are no more upcoming events in the selected month.',
            'bmscalendar_mon' => 'Mon',
            'bmscalendar_tue' => 'Tue',
            'bmscalendar_wed' => 'Wed',
            'bmscalendar_thu' => 'Thu',
            'bmscalendar_fri' => 'Fri',
            'bmscalendar_sat' => 'Sat',
            'bmscalendar_sun' => 'Sun',
            'bmscalendar_scope_my' => 'My calendar',
            'bmscalendar_scope_company' => 'Company',
            'bmscalendar_scope_my_help' => 'Your personal, course, group and site learning events within your access rights.',
            'bmscalendar_scope_company_help' => 'Events only from courses explicitly linked to the current company.',
            'bmscalendar_source_personal' => 'Personal event',
            'bmscalendar_source_site' => 'Learning site event',
            'bmscalendar_source_group' => 'Group event',
            'bmscalendar_source_company' => 'Company',
            'bmscalendar_source_moodle' => 'Learning',
            'bmscalendar_add' => 'Add event',
            'bmscalendar_edit' => 'Edit',
            'bmscalendar_delete' => 'Delete',
            'bmscalendar_editor_new' => 'New event',
            'bmscalendar_editor_edit' => 'Edit event',
            'bmscalendar_field_name' => 'Name',
            'bmscalendar_field_description' => 'Description',
            'bmscalendar_field_start' => 'Date and time',
            'bmscalendar_field_duration' => 'Duration, minutes',
            'bmscalendar_repeat' => 'Repeat',
            'bmscalendar_repeat_none' => 'Do not repeat',
            'bmscalendar_repeat_weekly' => 'Weekly',
            'bmscalendar_repeat_monthly' => 'Monthly',
            'bmscalendar_repeat_weekdays' => 'Selected weekdays',
            'bmscalendar_repeat_count' => 'Number of events',
            'bmscalendar_repeat_days' => 'Weekdays',
            'bmscalendar_repeat_note' => 'The series is created as ordinary independent events. Bulk editing of the whole series is not included yet.',
            'bmscalendar_field_target' => 'Calendar / course',
            'bmscalendar_target_personal' => 'Personal calendar',
            'bmscalendar_save' => 'Save',
            'bmscalendar_cancel' => 'Cancel',
            'bmscalendar_saved' => 'Event saved.',
            'bmscalendar_deleted' => 'Event deleted.',
            'bmscalendar_delete_confirm' => 'Delete this event?',
            'bmscalendar_readonly_generated' => 'Assignment, quiz, webinar and other module events must be changed in their source.',
            'bmscalendar_no_create_targets' => 'There is no calendar where you have permission to create events.',
            'bmscalendar_error_name' => 'Enter an event name.',
            'bmscalendar_error_name_long' => 'The event name is too long.',
            'bmscalendar_error_date' => 'Enter a valid date and time.',

            'bmscalendar_filter_search' => 'Search events',
            'bmscalendar_filter_kind' => 'Event type',
            'bmscalendar_filter_all' => 'All events',
            'bmscalendar_filter_manual' => 'Manual events',
            'bmscalendar_filter_generated' => 'From learning',
            'bmscalendar_filter_apply' => 'Apply',
            'bmscalendar_filter_reset' => 'Reset',
            'bmscalendar_kind_manual' => 'Manual event',
            'bmscalendar_kind_generated' => 'Learning event',
            'bmscalendar_event_details' => 'Event details',
            'bmscalendar_open_source' => 'Open in learning',
            'bmscalendar_detail_source' => 'Source',
            'bmscalendar_detail_type' => 'Type',
            'bmscalendar_detail_when' => 'When',
            'bmscalendar_detail_duration' => 'Duration',
            'bmscalendar_detail_description' => 'Description',
            'bmscalendar_no_description' => 'No description.',
            'bmscalendar_close' => 'Close',
            'bmscalendar_add_on_day' => 'Add event on this day',
            'bmscalendar_duration_none' => 'No duration',
            'bmscalendar_minutes_short' => 'min',
            'bmscalendar_hours_short' => 'h',
            'bmscalendar_source_filter' => 'Source',
            'bmscalendar_source_all' => 'All sources',
            'bmscalendar_source_task' => 'Tasks',
            'bmscalendar_source_meeting' => 'Meetings',
            'bmscalendar_source_battleplan' => 'Battle plan',
            'bmscalendar_source_checkpoint' => 'Checkpoints',
            'bmscalendar_source_cycle' => 'Management cycles',
            'bmscalendar_kind_cycle' => 'Management cycle',
            'bmscalendar_cycles_title' => 'Management cycles in period',
            'bmscalendar_cycles_intro' => 'Week, month, quarter and year are shown as one management-rhythm hierarchy.',
            'bmscalendar_cycles_empty' => 'There are no management cycles in the selected period.',
            'bmscalendar_cycle_period' => 'Period',
            'bmscalendar_kind_task' => 'BMS task',
            'bmscalendar_kind_meeting' => 'BMS meeting',
            'bmscalendar_kind_battleplan' => 'BMS battle plan',
            'bmscalendar_kind_checkpoint' => 'Management checkpoint',
            'bmscalendar_task_due' => 'Due',
            'bmscalendar_task_start' => 'Start',
            'bmscalendar_battleplan_prefix' => 'Battle plan',
            'bmscalendar_all_day' => 'All day',
            'bmscalendar_bms_events' => 'BMS events',
            'bmscalendar_bms_events_caption' => 'tasks, meetings, plans and checkpoints',
            'bmscalendar_moodle_events' => 'Learning',
            'bmscalendar_moodle_events_caption' => 'learning and personal events',
            'bmscalendar_open_bms_source' => 'Open BMS record',
            'bmscalendar_view_month' => 'Month',
            'bmscalendar_view_week' => 'Week',
            'bmscalendar_view_day' => 'Day',
            'bmscalendar_view_analytics' => 'Analytics',
            'bmscalendar_analytics_title' => 'Calendar analytics',
            'bmscalendar_analytics_intro' => 'Read-only analysis of the selected month from events already visible to you. Metrics are not stored and are not employee performance scores.',
            'bmscalendar_analytics_meeting_hours' => 'Meeting time',
            'bmscalendar_analytics_meeting_density' => 'Meeting density',
            'bmscalendar_analytics_focus' => 'Focus-time capacity',
            'bmscalendar_analytics_overdue' => 'Overdue tasks',
            'bmscalendar_analytics_heavy_days' => 'Meeting-heavy days',
            'bmscalendar_analytics_conflicts' => 'Days with overlaps',
            'bmscalendar_analytics_regular' => 'Regular-management signals',
            'bmscalendar_analytics_team' => 'Team workload',
            'bmscalendar_analytics_team_intro' => 'Workload warning: 75% or more of the working window is occupied by timed events, or time overlaps are present.',
            'bmscalendar_analytics_load' => 'Load',
            'bmscalendar_analytics_warning' => 'Needs attention',
            'bmscalendar_analytics_ok' => 'Within guideline',
            'bmscalendar_analytics_workdays' => 'working days',
            'bmscalendar_analytics_meetings' => 'meetings',
            'bmscalendar_analytics_hours' => 'h',
            'bmscalendar_drag_hint' => 'Manual Learning events can be dragged to another day. The event time is preserved.',
            'bmscalendar_moved' => 'Event moved.',
            'bmscalendar_error_move_date' => 'Could not determine the new event date.',
            'bmscalendar_move_confirm' => 'Move the event to the selected day?',
            'bmscalendar_day_busy' => 'Busy time',
            'bmscalendar_day_conflicts' => 'Events with conflicts',
            'bmscalendar_add_at_time' => 'Add an event at this time',
            'bmscalendar_conflict_badge' => 'Conflict',
            'bmscalendar_conflict_notice' => 'Some events overlap in time. Review them before confirming the day plan.',
            'bmscalendar_workload_title' => 'Workload',
            'bmscalendar_workload_team' => 'Team workload',
            'bmscalendar_workload_me' => 'My workload',
            'bmscalendar_employee_filter' => 'Employee',
            'bmscalendar_employee_all' => 'Whole team',
            'bmscalendar_workload_events' => 'events',
            'bmscalendar_workload_tasks' => 'tasks',
            'bmscalendar_workload_meetings' => 'meetings',
            'bmscalendar_workload_busy' => 'busy',
            'bmscalendar_workload_conflicts' => 'conflicts',
            'bmscalendar_workload_privacy' => 'Team workload uses only BMS records you are already allowed to see. Other employees personal learning calendars are never read.',
            'bmscalendar_finder_title' => 'Find meeting time',
            'bmscalendar_finder_intro' => 'Choose participants, date and duration. BMS24 suggests common free windows from management workload visible to you.',
            'bmscalendar_finder_date' => 'Date',
            'bmscalendar_finder_duration' => 'Duration',
            'bmscalendar_finder_members' => 'Participants',
            'bmscalendar_finder_find' => 'Find time',
            'bmscalendar_finder_slots' => 'Suggested windows',
            'bmscalendar_finder_none' => 'No suitable intervals were found in the 09:00–18:00 working window.',
            'bmscalendar_finder_create' => 'Create meeting',
            'bmscalendar_finder_privacy' => 'Suggestions use only BMS events already visible to you. Other employees personal learning calendars are never read, so availability is advisory rather than guaranteed.',
            'bmscalendar_finder_workhours' => 'Working window',
            'bmscalendar_planmeet_title' => 'Planning meeting: agenda → decisions → tasks',
            'bmscalendar_planmeet_intro' => 'Your upcoming meetings are shown as one management execution flow. All mutations stay in the canonical meeting workspace.',
            'bmscalendar_planmeet_empty' => 'There are no scheduled meetings in the next 7 days.',
            'bmscalendar_planmeet_create' => 'Create planning meeting',
            'bmscalendar_planmeet_agenda' => 'Agenda',
            'bmscalendar_planmeet_decisions' => 'Decisions',
            'bmscalendar_planmeet_tasks' => 'Tasks',
            'bmscalendar_planmeet_open' => 'Open planning meeting',
            'bmscalendar_planmeet_close' => 'Ready to close',
            'bms_google_title' => 'Google Calendar',
            'bms_google_source_label' => 'Google Calendar',
        ];
        $fallback = (strpos(current_language(), 'ru') === 0) ? $ru : $en;
        return $fallback[$key] ?? $key;
    }

    private static function tr(string $key, $a = null): string {
        return self::text($key, $a);
    }

    public static function render(string $companyuuid, array $model, ?array $editor = null, ?array $detail = null): string {
        $actions = '';
        if (!empty($model['create_targets'])) {
            $actions .= \html_writer::link($model['links']['create'], self::tr('bmscalendar_add'), [
                'class' => 'btn btn-success',
            ]);
        }
        $actions .= \html_writer::link($model['links']['moodle'], self::tr('bmscalendar_open_moodle'), [
            'class' => 'btn btn-outline-secondary ml-2',
        ]);
        $actions .= \html_writer::link(new \moodle_url('/local/kbacompany/calendar_google.php', ['uuid'=>$companyuuid]),
            self::tr('bms_google_title'), ['class'=>'btn btn-outline-secondary ml-2']);
        $out = bms_workspace_ux_service::record_header(
            self::tr('bmscalendar_title'),
            self::tr('bmscalendar_intro'),
            format_string((string)$model['company']['name']),
            '',
            $actions
        );
        $out .= \html_writer::div(self::tr('bmscalendar_boundary_notice'), 'alert alert-info');
        $out .= \html_writer::div(self::tr('bmscalendar_readonly_generated'), 'alert alert-light');
        $out .= \html_writer::div(self::tr('bmscalendar_drag_hint'), 'bms-calendar-drag-hint text-muted');

        if ($editor !== null) {
            $out .= self::render_editor($companyuuid, $model, $editor);
        }
        if ($detail !== null) {
            $out .= self::render_detail($companyuuid, $model, $detail);
        }

        $scope = (string)($model['scope'] ?? 'my');
        $myclass = 'btn ' . ($scope === 'my' ? 'btn-success' : 'btn-outline-secondary');
        $companyclass = 'btn ' . ($scope === 'company' ? 'btn-success' : 'btn-outline-secondary');
        $scopenav = \html_writer::link($model['links']['my'], self::tr('bmscalendar_scope_my'), ['class' => $myclass]);
        $scopenav .= \html_writer::link($model['links']['company'], self::tr('bmscalendar_scope_company'), ['class' => $companyclass]);
        $scopehelp = $scope === 'company' ? self::tr('bmscalendar_scope_company_help') : self::tr('bmscalendar_scope_my_help');
        $out .= \html_writer::div(
            \html_writer::div($scopenav, 'bms-calendar-month-actions') .
            \html_writer::div(s($scopehelp), 'text-muted mt-2'),
            'bms-calendar-scope mb-3'
        );
        $out .= self::render_filters($companyuuid, $model);
        if (($model['view'] ?? 'month') !== 'analytics') {
            $out .= self::render_workload($companyuuid, $model);
            $out .= self::render_meeting_finder($companyuuid, $model);
            if (in_array((string)($model['source_filter'] ?? 'all'), ['all','meeting'], true)) {
                $out .= self::render_meeting_workflow($companyuuid, $model);
            }
            if (in_array((string)($model['source_filter'] ?? 'all'), ['all','cycle'], true)) {
                $out .= self::render_cycle_overview($companyuuid, $model);
            }
            $out .= self::render_management_rhythm($companyuuid, $model);
        }

        $viewnav = '';
        foreach (['month'=>'bmscalendar_view_month','week'=>'bmscalendar_view_week','day'=>'bmscalendar_view_day','analytics'=>'bmscalendar_view_analytics'] as $viewkey=>$labelkey) {
            $class = 'btn ' . (($model['view'] ?? 'month') === $viewkey ? 'btn-success' : 'btn-outline-secondary');
            $viewnav .= \html_writer::link($model['links']['view_'.$viewkey], self::tr($labelkey), ['class'=>$class]);
        }
        $out .= \html_writer::div($viewnav, 'bms-calendar-view-nav');

        $summary = $model['summary'];
        $sources = (array)($summary['sources'] ?? []);
        $bmscount = (int)($sources['task'] ?? 0) + (int)($sources['meeting'] ?? 0) +
            (int)($sources['battleplan'] ?? 0) + (int)($sources['checkpoint'] ?? 0) + (int)($sources['cycle'] ?? 0);
        $cards = [
            [self::tr('bmscalendar_events'), (int)$summary['events'], self::tr('bmscalendar_events_caption')],
            [self::tr('bmscalendar_bms_events'), $bmscount, self::tr('bmscalendar_bms_events_caption')],
            [self::tr('bmscalendar_moodle_events'), (int)($sources['moodle'] ?? 0), self::tr('bmscalendar_moodle_events_caption')],
            [self::tr('bmscalendar_upcoming'), (int)$summary['upcoming'], self::tr('bmscalendar_upcoming_caption')],
        ];
        $out .= \html_writer::start_div('bms-calendar-summary');
        foreach ($cards as $card) {
            $body = \html_writer::div(s($card[0]), 'bms-calendar-kpi-label');
            $body .= \html_writer::div((string)$card[1], 'bms-calendar-kpi-value');
            $body .= \html_writer::div(s($card[2]), 'bms-calendar-kpi-caption');
            $out .= \html_writer::div($body, 'bms-calendar-kpi');
        }
        $out .= \html_writer::end_div();

        $nav = \html_writer::link($model['links']['prev'], '‹ ' . self::tr('bmscalendar_prev'), ['class' => 'btn btn-outline-secondary']);
        $nav .= \html_writer::link($model['links']['today'], self::tr('bmscalendar_today'), ['class' => 'btn btn-outline-secondary']);
        $nav .= \html_writer::link($model['links']['next'], self::tr('bmscalendar_next') . ' ›', ['class' => 'btn btn-outline-secondary']);
        $out .= \html_writer::div(
            \html_writer::tag('h2', s(ucfirst((string)($model['view_label'] ?? $model['month_label']))), ['class' => 'bms-calendar-month-title']) .
            \html_writer::div($nav, 'bms-calendar-month-actions'),
            'bms-calendar-toolbar'
        );

        if (($model['view'] ?? 'month') === 'analytics') {
            $out .= self::render_analytics($model);
            return $out;
        }

        if (empty($model['courses']) && ($model['scope'] ?? 'my') === 'company' && in_array(($model['source_filter'] ?? 'all'), ['all','moodle'], true)) {
            $out .= \html_writer::div(self::tr('bmscalendar_no_courses'), 'alert alert-warning');
        }

        $weekdays = [
            self::tr('bmscalendar_mon'), self::tr('bmscalendar_tue'), self::tr('bmscalendar_wed'),
            self::tr('bmscalendar_thu'), self::tr('bmscalendar_fri'), self::tr('bmscalendar_sat'),
            self::tr('bmscalendar_sun'),
        ];
        $grid = '';
        $view = (string)($model['view'] ?? 'month');
        if ($view === 'day' && !empty($model['day_planner'])) {
            $out .= self::render_day_planner($companyuuid, $model);
        } else {
            foreach ($weekdays as $weekday) {
                $grid .= \html_writer::div(s($weekday), 'bms-calendar-weekday');
            }
            if ($view === 'month') {
                for ($blank = 1; $blank < (int)($model['display_start_weekday'] ?? $model['start_weekday']); $blank++) {
                    $grid .= \html_writer::div('', 'bms-calendar-day is-empty');
                }
            }
            foreach (($model['display_days'] ?? $model['days']) as $day) {
                $events = '';
                foreach ($day['events'] as $event) {
                    $eventhtml = \html_writer::span(s($event['time_label']), 'bms-calendar-event-time');
                    $eventhtml .= \html_writer::span(s($event['name']), 'bms-calendar-event-name');
                    $eventhtml .= \html_writer::span(s($event['course_name']), 'bms-calendar-event-source');
                    $eventcard = \html_writer::link($event['url'], $eventhtml, [
                        'class' => 'bms-calendar-event-main',
                        'title' => $event['course_name'],
                    ]);
                    if (!empty($event['can_manage'])) {
                        $eventcard .= self::event_actions($companyuuid, $model, $event);
                    }
                    $sourceclass = preg_replace('/[^a-z0-9_-]/', '', (string)($event['source_kind'] ?? 'moodle'));
                    $attrs = ['class'=>'bms-calendar-event source-' . $sourceclass];
                    if (!empty($event['can_manage']) && ($event['source_kind'] ?? '') === 'moodle' && (int)($event['id'] ?? 0) > 0) {
                        $attrs['draggable'] = 'true';
                        $attrs['data-calendar-event-id'] = (string)(int)$event['id'];
                        $attrs['data-calendar-draggable'] = '1';
                    }
                    $events .= \html_writer::tag('div', $eventcard, $attrs);
                }
                $classes = 'bms-calendar-day' . (!empty($day['today']) ? ' is-today' : '');
                $daynumber = \html_writer::span((string)$day['day']);
                if (!empty($model['create_targets'])) {
                    $quick = new \moodle_url('/local/kbacompany/calendar.php', [
                        'uuid' => $companyuuid, 'scope' => $model['scope'], 'month' => $model['month'],
                        'q' => $model['query'], 'kind' => $model['kind'], 'source' => (($model['source_filter'] ?? 'all') === 'all' ? 'all' : 'moodle'), 'member'=>(int)($model['member_id'] ?? 0),
                        'view' => $model['view'], 'date' => $day['date'], 'action' => 'new',
                    ]);
                    $daynumber .= \html_writer::link($quick, '+', [
                        'class' => 'bms-calendar-day-add', 'title' => self::tr('bmscalendar_add_on_day'),
                    ]);
                }
                if ($view !== 'month') {
                    $daynumber = \html_writer::span(s($weekdays[(int)$day['weekday'] - 1]), 'bms-calendar-day-weekday') . $daynumber;
                }
                $cell = \html_writer::div($daynumber, 'bms-calendar-day-number');
                $cell .= \html_writer::div($events, 'bms-calendar-day-events');
                $grid .= \html_writer::tag('div', $cell, [
                    'class'=>$classes,
                    'data-calendar-drop-date'=>$day['date'],
                ]);
            }
            $gridclass = 'bms-calendar-grid view-' . preg_replace('/[^a-z]/', '', $view);
            $out .= \html_writer::div(\html_writer::div($grid, $gridclass), 'bms-calendar-scroll');
        }

        $moveform = \html_writer::start_tag('form', [
            'method'=>'post', 'action'=>(new \moodle_url('/local/kbacompany/calendar.php'))->out(false),
            'class'=>'bms-calendar-move-form', 'hidden'=>'hidden',
            'data-confirm'=>self::tr('bmscalendar_move_confirm'),
        ]);
        foreach ([
            'sesskey'=>sesskey(), 'operation'=>'move', 'uuid'=>$companyuuid, 'scope'=>$model['scope'],
            'month'=>$model['month'], 'q'=>$model['query'], 'kind'=>$model['kind'], 'source'=>$model['source_filter'],
            'view'=>$model['view'], 'date'=>$model['anchor_date'], 'member'=>(int)($model['member_id'] ?? 0), 'eventid'=>'', 'targetdate'=>'',
        ] as $name=>$value) {
            $moveform .= \html_writer::empty_tag('input', ['type'=>'hidden','name'=>$name,'value'=>$value]);
        }
        $moveform .= \html_writer::end_tag('form');
        $out .= $moveform;

        $out .= \html_writer::tag('h2', self::tr('bmscalendar_upcoming_title'), ['class' => 'bms-calendar-section-title']);
        if (empty($model['upcoming'])) {
            $out .= \html_writer::div(self::tr('bmscalendar_no_upcoming'), 'alert alert-light');
        } else {
            $items = '';
            foreach ($model['upcoming'] as $event) {
                $date = \html_writer::div(s($event['date_label'] . ' · ' . $event['time_label']), 'bms-calendar-upcoming-date');
                $title = \html_writer::link($event['url'], s($event['name']), ['class' => 'bms-calendar-upcoming-name']);
                $source = \html_writer::div(s($event['course_name']), 'bms-calendar-upcoming-source');
                $actions = !empty($event['can_manage']) ? self::event_actions($companyuuid, $model, $event) : '';
                $sourceclass = preg_replace('/[^a-z0-9_-]/', '', (string)($event['source_kind'] ?? 'moodle'));
                $items .= \html_writer::tag('li', $date . $title . $source . $actions, ['class' => 'bms-calendar-upcoming-item source-' . $sourceclass]);
            }
            $out .= \html_writer::tag('ul', $items, ['class' => 'bms-calendar-upcoming-list']);
        }
        return $out;
    }
    private static function render_analytics(array $model): string {
        $a = (array)($model['analytics'] ?? []);
        if (!$a) { return ''; }
        $hours = static function(int $minutes): string {
            $value = round($minutes / 60, 1);
            return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
        };
        $cards = [
            [self::tr('bmscalendar_analytics_meeting_hours'), $hours((int)$a['meeting_minutes']) . ' ' . self::tr('bmscalendar_analytics_hours'),
                (int)$a['meeting_count'] . ' ' . self::tr('bmscalendar_analytics_meetings')],
            [self::tr('bmscalendar_analytics_meeting_density'), (string)$a['meeting_density'] . '%',
                (int)$a['workdays'] . ' ' . self::tr('bmscalendar_analytics_workdays')],
            [self::tr('bmscalendar_analytics_focus'), $hours((int)$a['focus_minutes']) . ' ' . self::tr('bmscalendar_analytics_hours'),
                self::tr('bmscalendar_analytics_intro')],
            [self::tr('bmscalendar_analytics_overdue'), (string)(int)$a['overdue_tasks'],
                self::tr('bmscalendar_source_task')],
            [self::tr('bmscalendar_analytics_heavy_days'), (string)(int)$a['meeting_heavy_days'],
                '≥ 4 ' . self::tr('bmscalendar_analytics_hours')],
            [self::tr('bmscalendar_analytics_conflicts'), (string)(int)$a['conflict_days'],
                self::tr('bmscalendar_day_conflicts')],
            [self::tr('bmscalendar_analytics_regular'), (string)(int)$a['rhythm_signals'],
                s((string)($a['rhythm_state'] ?? ''))],
        ];
        $out = \html_writer::tag('h2', self::tr('bmscalendar_analytics_title'), ['class'=>'bms-calendar-section-title']);
        $out .= \html_writer::div(self::tr('bmscalendar_analytics_intro'), 'text-muted mb-3');
        $grid = '';
        foreach ($cards as $card) {
            $body = \html_writer::div(s((string)$card[0]), 'bms-calendar-kpi-label');
            $body .= \html_writer::div(s((string)$card[1]), 'bms-calendar-kpi-value');
            $body .= \html_writer::div((string)$card[2], 'bms-calendar-kpi-caption');
            $grid .= \html_writer::div($body, 'bms-calendar-kpi');
        }
        $out .= \html_writer::div($grid, 'bms-calendar-analytics-kpis');
        if (!empty($a['team_visible']) && !empty($a['team'])) {
            $out .= \html_writer::tag('h3', self::tr('bmscalendar_analytics_team'), ['class'=>'mt-4 mb-1']);
            $out .= \html_writer::div(self::tr('bmscalendar_analytics_team_intro'), 'text-muted mb-2');
            $rows = '';
            foreach ((array)$a['team'] as $member) {
                $status = !empty($member['warning']) ? self::tr('bmscalendar_analytics_warning') : self::tr('bmscalendar_analytics_ok');
                $statusclass = !empty($member['warning']) ? 'badge badge-warning' : 'badge badge-success';
                $name = \html_writer::div(s((string)$member['name']), 'bms-calendar-analytics-member-name');
                if ((string)($member['position'] ?? '') !== '') {
                    $name .= \html_writer::div(s((string)$member['position']), 'text-muted small');
                }
                $metrics = \html_writer::span(self::tr('bmscalendar_analytics_load') . ': ' . s((string)$member['load_pct']) . '%');
                $metrics .= \html_writer::span((int)$member['meetings'] . ' ' . self::tr('bmscalendar_analytics_meetings'));
                $metrics .= \html_writer::span($hours((int)$member['busy_minutes']) . ' ' . self::tr('bmscalendar_analytics_hours'));
                $metrics .= \html_writer::span((int)$member['conflicts'] . ' ' . self::tr('bmscalendar_workload_conflicts'));
                $rows .= \html_writer::div($name . \html_writer::div($metrics, 'bms-calendar-analytics-member-metrics') .
                    \html_writer::span($status, $statusclass), 'bms-calendar-analytics-member');
            }
            $out .= \html_writer::div($rows, 'bms-calendar-analytics-team');
        }
        return $out;
    }

    private static function render_workload(string $companyuuid, array $model): string {
        $load = (array)($model['team_load'] ?? []);
        $members = (array)($load['members'] ?? []);
        if (!$members) { return ''; }
        $selected = (int)($model['member_id'] ?? 0);
        $canteam = !empty($load['can_view_team']);
        $title = $canteam ? self::tr('bmscalendar_workload_team') : self::tr('bmscalendar_workload_me');
        $out = \html_writer::tag('h2', $title, ['class'=>'bms-calendar-section-title bms-calendar-workload-heading']);
        if ($canteam) {
            $options = \html_writer::tag('option', self::tr('bmscalendar_employee_all'), ['value'=>'']);
            foreach ($members as $member) {
                $label = (string)$member['name'];
                if ((string)($member['position'] ?? '') !== '') { $label .= ' — ' . (string)$member['position']; }
                $attrs=['value'=>(int)$member['user_id']]; if ((int)$member['user_id']===$selected){$attrs['selected']='selected';}
                $options .= \html_writer::tag('option', s($label), $attrs);
            }
            $form = \html_writer::start_tag('form',['method'=>'get','action'=>(new \moodle_url('/local/kbacompany/calendar.php'))->out(false),'class'=>'bms-calendar-workload-filter']);
            foreach (['uuid'=>$companyuuid,'scope'=>$model['scope'],'month'=>$model['month'],'q'=>$model['query'],'kind'=>$model['kind'],'source'=>$model['source_filter'],'view'=>$model['view'],'date'=>$model['anchor_date']] as $name=>$value) {
                $form .= \html_writer::empty_tag('input',['type'=>'hidden','name'=>$name,'value'=>$value]);
            }
            $form .= \html_writer::label(self::tr('bmscalendar_employee_filter'),'bms-calendar-member-filter');
            $form .= \html_writer::select([], 'member', null, false, ['id'=>'bms-calendar-member-filter','onchange'=>'this.form.submit()']);
            // html_writer::select cannot consume pre-rendered options, so replace its empty body safely.
            $form = str_replace('</select>', $options.'</select>', $form);
            $form .= \html_writer::end_tag('form');
            $out .= $form;
        }
        $cards='';
        foreach ($members as $member) {
            if ($selected > 0 && (int)$member['user_id'] !== $selected) { continue; }
            $metrics = \html_writer::span((int)$member['events'].' '.self::tr('bmscalendar_workload_events'));
            $metrics .= \html_writer::span((int)$member['tasks'].' '.self::tr('bmscalendar_workload_tasks'));
            $metrics .= \html_writer::span((int)$member['meetings'].' '.self::tr('bmscalendar_workload_meetings'));
            $metrics .= \html_writer::span(self::duration_label_minutes((int)$member['timed_minutes']).' '.self::tr('bmscalendar_workload_busy'));
            if ((int)$member['conflicts'] > 0) { $metrics .= \html_writer::span((int)$member['conflicts'].' '.self::tr('bmscalendar_workload_conflicts'),'has-conflict'); }
            $name = \html_writer::div(s((string)$member['name']),'bms-calendar-workload-name');
            if ((string)($member['position'] ?? '') !== '') { $name .= \html_writer::div(s((string)$member['position']),'bms-calendar-workload-position'); }
            $cards .= \html_writer::div($name.\html_writer::div($metrics,'bms-calendar-workload-metrics'),'bms-calendar-workload-card'.((int)$member['user_id']===(int)$load['current_user_id']?' is-me':''));
        }
        $out .= \html_writer::div($cards,'bms-calendar-workload-grid');
        $out .= \html_writer::div(self::tr('bmscalendar_workload_privacy'),'text-muted bms-calendar-workload-note');
        return \html_writer::div($out,'bms-calendar-workload');
    }


    private static function render_meeting_finder(string $companyuuid, array $model): string {
        $finder = (array)($model['meeting_finder'] ?? []);
        if (!$finder) { return ''; }
        $out = \html_writer::tag('h2', self::tr('bmscalendar_finder_title'), ['class'=>'bms-calendar-section-title']);
        $out .= \html_writer::div(self::tr('bmscalendar_finder_intro'), 'text-muted mb-2');
        $form = \html_writer::start_tag('form', [
            'method'=>'get', 'action'=>(new \moodle_url('/local/kbacompany/calendar.php'))->out(false),
            'class'=>'bms-calendar-finder-form',
        ]);
        foreach ([
            'uuid'=>$companyuuid, 'scope'=>$model['scope'], 'month'=>$model['month'], 'q'=>$model['query'],
            'kind'=>$model['kind'], 'source'=>$model['source_filter'], 'view'=>$model['view'],
            'date'=>$model['anchor_date'], 'member'=>(int)($model['member_id'] ?? 0), 'find'=>1,
        ] as $name=>$value) {
            $form .= \html_writer::empty_tag('input', ['type'=>'hidden','name'=>$name,'value'=>$value]);
        }
        $form .= \html_writer::div(
            \html_writer::tag('label', self::tr('bmscalendar_finder_date'), ['for'=>'bms-calendar-finddate']) .
            \html_writer::empty_tag('input', ['type'=>'date','id'=>'bms-calendar-finddate','name'=>'finddate','value'=>$finder['date'],'class'=>'form-control','required'=>'required']),
            'bms-calendar-finder-field'
        );
        $durationopts='';
        foreach ([30,45,60,90,120] as $minutes) {
            $attrs=['value'=>$minutes]; if ((int)$finder['duration']===$minutes) { $attrs['selected']='selected'; }
            $durationopts .= \html_writer::tag('option', $minutes.' '.self::tr('bmscalendar_minutes_short'), $attrs);
        }
        $form .= \html_writer::div(
            \html_writer::tag('label', self::tr('bmscalendar_finder_duration'), ['for'=>'bms-calendar-findduration']) .
            \html_writer::tag('select',$durationopts,['id'=>'bms-calendar-findduration','name'=>'findduration','class'=>'form-control']),
            'bms-calendar-finder-field'
        );
        $memberopts='';
        foreach ((array)$finder['members'] as $member) {
            $label=(string)$member['name']; if ((string)($member['position'] ?? '') !== '') { $label .= ' — '.(string)$member['position']; }
            $attrs=['value'=>(int)$member['user_id']]; if (!empty($member['selected'])) { $attrs['selected']='selected'; }
            $memberopts .= \html_writer::tag('option', s($label), $attrs);
        }
        $form .= \html_writer::div(
            \html_writer::tag('label', self::tr('bmscalendar_finder_members'), ['for'=>'bms-calendar-findmembers']) .
            \html_writer::tag('select',$memberopts,['id'=>'bms-calendar-findmembers','name'=>'findmembers[]','class'=>'form-control','multiple'=>'multiple','size'=>min(6,max(2,count((array)$finder['members'])))]),
            'bms-calendar-finder-field is-members'
        );
        $form .= \html_writer::div(
            \html_writer::tag('button', self::tr('bmscalendar_finder_find'), ['type'=>'submit','class'=>'btn btn-success']),
            'bms-calendar-finder-actions'
        );
        $form .= \html_writer::end_tag('form');
        $out .= $form;
        $out .= \html_writer::div(self::tr('bmscalendar_finder_workhours').': '.s((string)$finder['working_hours']), 'bms-calendar-finder-hours');
        if (!empty($finder['submitted'])) {
            $slots='';
            foreach ((array)$finder['slots'] as $slot) {
                $slots .= \html_writer::div(
                    \html_writer::tag('strong', s((string)$slot['label'])) .
                    \html_writer::link($slot['meeting_url'], self::tr('bmscalendar_finder_create'), ['class'=>'btn btn-sm btn-outline-success']),
                    'bms-calendar-finder-slot'
                );
            }
            $out .= \html_writer::tag('h3', self::tr('bmscalendar_finder_slots'), ['class'=>'bms-calendar-finder-subtitle']);
            $out .= $slots !== '' ? \html_writer::div($slots,'bms-calendar-finder-slots') : \html_writer::div(self::tr('bmscalendar_finder_none'),'alert alert-warning');
        }
        $out .= \html_writer::div(self::tr('bmscalendar_finder_privacy'),'text-muted bms-calendar-finder-note');
        return \html_writer::div($out,'bms-calendar-finder');
    }

    /** Render an hourly vertical planner for day view. */
    private static function render_day_planner(string $companyuuid, array $model): string {
        $planner = $model['day_planner'];
        $out = '';
        $occupied = (int)($planner['occupied_minutes'] ?? 0);
        $conflicts = (int)($planner['conflict_events'] ?? 0);
        $summary = \html_writer::div(
            \html_writer::div(
                \html_writer::span(self::tr('bmscalendar_day_busy'), 'bms-calendar-planner-stat-label') .
                \html_writer::tag('strong', self::duration_label_minutes($occupied), ['class'=>'bms-calendar-planner-stat-value']),
                'bms-calendar-planner-stat'
            ) .
            \html_writer::div(
                \html_writer::span(self::tr('bmscalendar_day_conflicts'), 'bms-calendar-planner-stat-label') .
                \html_writer::tag('strong', (string)$conflicts, ['class'=>'bms-calendar-planner-stat-value']),
                'bms-calendar-planner-stat' . ($conflicts > 0 ? ' has-conflict' : '')
            ),
            'bms-calendar-planner-summary'
        );
        $out .= $summary;

        if (!empty($planner['all_day'])) {
            $cards = '';
            foreach ($planner['all_day'] as $event) {
                $sourceclass = preg_replace('/[^a-z0-9_-]/', '', (string)($event['source_kind'] ?? 'moodle'));
                $body = \html_writer::span(s($event['name']), 'bms-calendar-event-name') .
                    \html_writer::span(s($event['course_name']), 'bms-calendar-event-source');
                $cards .= \html_writer::link($event['url'], $body, ['class'=>'bms-calendar-planner-allday-event source-'.$sourceclass]);
            }
            $out .= \html_writer::div(
                \html_writer::span(self::tr('bmscalendar_all_day'), 'bms-calendar-planner-allday-label') .
                \html_writer::div($cards, 'bms-calendar-planner-allday-events'),
                'bms-calendar-planner-allday'
            );
        }

        $start = (int)$planner['start_minute'];
        $end = (int)$planner['end_minute'];
        $height = max(64, (int)(($end - $start) / 60) * 64);
        $hours = '';
        foreach ($planner['hours'] as $hour) {
            $top = ((int)$hour['minute'] - $start) / 60 * 64;
            $label = \html_writer::span(s($hour['label']), 'bms-calendar-planner-hour-label');
            $add = '';
            if (!empty($model['create_targets'])) {
                $quick = new \moodle_url('/local/kbacompany/calendar.php', [
                    'uuid'=>$companyuuid, 'scope'=>$model['scope'], 'month'=>$model['month'],
                    'q'=>$model['query'], 'kind'=>$model['kind'],
                    'source'=>(($model['source_filter'] ?? 'all') === 'all' ? 'all' : 'moodle'),
                    'view'=>'day', 'date'=>$hour['datetime'], 'action'=>'new',
                ]);
                $add = \html_writer::link($quick, '+', ['class'=>'bms-calendar-planner-hour-add','title'=>self::tr('bmscalendar_add_at_time')]);
            }
            $hours .= \html_writer::div($label . $add, 'bms-calendar-planner-hour', [
                'style'=>'top:'.(int)$top.'px',
            ]);
        }

        $eventhtml = '';
        $lanes = max(1, (int)$planner['lane_count']);
        foreach ($planner['timed'] as $event) {
            $top = ((int)$event['planner_start_minute'] - $start) / 60 * 64;
            $heightpx = max(32, (int)round((int)$event['planner_display_minutes'] / 60 * 64));
            $lane = (int)($event['planner_lane'] ?? 0);
            $width = 100 / $lanes;
            $left = $lane * $width;
            $sourceclass = preg_replace('/[^a-z0-9_-]/', '', (string)($event['source_kind'] ?? 'moodle'));
            $classes = 'bms-calendar-planner-event source-'.$sourceclass . (!empty($event['planner_conflict']) ? ' has-conflict' : '');
            $body = \html_writer::span(s($event['time_label']), 'bms-calendar-planner-event-time') .
                \html_writer::span(s($event['name']), 'bms-calendar-planner-event-name') .
                \html_writer::span(s($event['course_name']), 'bms-calendar-planner-event-source');
            if (!empty($event['planner_conflict'])) {
                $body .= \html_writer::span(self::tr('bmscalendar_conflict_badge'), 'bms-calendar-planner-conflict-badge');
            }
            $attrs = [
                'class'=>$classes,
                'style'=>'top:'.(int)$top.'px;height:'.$heightpx.'px;left:calc('.round($left,3).'% + 72px);width:calc('.round($width,3).'% - 78px);',
                'title'=>$event['course_name'],
            ];
            $eventhtml .= \html_writer::link($event['url'], $body, $attrs);
        }

        $canvas = \html_writer::div($hours . $eventhtml, 'bms-calendar-planner-canvas', ['style'=>'height:'.$height.'px']);
        $out .= \html_writer::div($canvas, 'bms-calendar-planner-scroll');
        if ($conflicts > 0) {
            $out .= \html_writer::div(self::tr('bmscalendar_conflict_notice'), 'alert alert-warning bms-calendar-conflict-notice');
        }
        return $out;
    }

    private static function duration_label_minutes(int $minutes): string {
        if ($minutes <= 0) { return '0 ' . self::tr('bmscalendar_minutes_short'); }
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;
        if ($hours <= 0) { return $rest . ' ' . self::tr('bmscalendar_minutes_short'); }
        return $rest > 0
            ? $hours . ' ' . self::tr('bmscalendar_hours_short') . ' ' . $rest . ' ' . self::tr('bmscalendar_minutes_short')
            : $hours . ' ' . self::tr('bmscalendar_hours_short');
    }

    /** Render Agenda → Decisions → Tasks workflow for nearest participant-scoped meetings. */
    private static function render_meeting_workflow(string $companyuuid, array $model): string {
        $workflow = (array)($model['meeting_workflow'] ?? []);
        $meetings = (array)($workflow['meetings'] ?? []);
        $head = \html_writer::tag('h3', self::tr('bmscalendar_planmeet_title'), ['class'=>'mb-1']);
        $head .= \html_writer::div(self::tr('bmscalendar_planmeet_intro'), 'text-muted mb-2');
        if (!$meetings) {
            $empty = \html_writer::div(self::tr('bmscalendar_planmeet_empty'), 'text-muted mb-2');
            $create = !empty($workflow['create_url'])
                ? \html_writer::link($workflow['create_url'], self::tr('bmscalendar_planmeet_create'), ['class'=>'btn btn-sm btn-success']) : '';
            return \html_writer::div($head.$empty.$create, 'bms-calendar-planmeet mb-3');
        }
        $cards='';
        foreach ($meetings as $meeting) {
            $flow=(array)($meeting['workflow'] ?? []);
            $meetingurl=new \moodle_url('/local/kbacompany/meetings.php',['uuid'=>$companyuuid,'meeting'=>(string)$meeting['uuid']]);
            $agendaurl=clone $meetingurl; $agendaurl->set_anchor('meeting-agenda');
            $decisionurl=clone $meetingurl; $decisionurl->set_anchor('meeting-decisions');
            $taskurl=clone $meetingurl; $taskurl->set_anchor('meeting-decisions');
            $closeurl=clone $meetingurl; $closeurl->set_anchor('meeting-close');
            $time=(int)$meeting['starts_at']>0 ? userdate((int)$meeting['starts_at'], get_string('strftimedatetimeshort')) : self::tr('bmscalendar_all_day');
            $meta=s($time.' · '.(string)$meeting['status']);
            if (trim((string)$meeting['chair_name'])!=='') { $meta .= ' · '.s((string)$meeting['chair_name']); }
            $steps='';
            $stepdata=[
                ['bmscalendar_planmeet_agenda',(int)($flow['agenda_total']??0),(int)($flow['agenda_open']??0),$agendaurl],
                ['bmscalendar_planmeet_decisions',(int)($flow['decisions_total']??0),(int)($flow['decisions_proposed']??0),$decisionurl],
                ['bmscalendar_planmeet_tasks',(int)($flow['tasks_total']??0),(int)($flow['accepted_without_task']??0),$taskurl],
            ];
            foreach($stepdata as $step){
                $attention=(int)$step[2]>0;
                $body=\html_writer::span(self::tr($step[0]),'bms-calendar-planmeet-step-label').
                    \html_writer::tag('strong',(string)$step[1],['class'=>'bms-calendar-planmeet-step-value']);
                if($attention){$body.=\html_writer::span((string)$step[2], 'badge badge-warning');}
                $steps.=\html_writer::link($step[3],$body,['class'=>'bms-calendar-planmeet-step'.($attention?' has-attention':'')]);
            }
            $actions=\html_writer::link($meetingurl,self::tr('bmscalendar_planmeet_open'),['class'=>'btn btn-sm btn-outline-primary']);
            if(!empty($flow['ready_to_close']) && !empty($meeting['can_govern'])){
                $actions.=\html_writer::link($closeurl,self::tr('bmscalendar_planmeet_close'),['class'=>'btn btn-sm btn-outline-success ml-1']);
            }
            $title=\html_writer::tag('strong',s((string)$meeting['title'])).\html_writer::div($meta,'small text-muted');
            $cards.=\html_writer::div($title.\html_writer::div($steps,'bms-calendar-planmeet-steps').
                \html_writer::div($actions,'bms-calendar-planmeet-actions'),'bms-calendar-planmeet-card');
        }
        return \html_writer::div($head.$cards,'bms-calendar-planmeet mb-3');
    }

    private static function render_management_rhythm(string $companyuuid, array $model): string {
        $rhythm = (array)($model['management_rhythm'] ?? []);
        if (empty($rhythm['available'])) { return ''; }
        $state = (string)($rhythm['state'] ?? '');
        $stats = [
            [self::tr('bmscalendar_rhythm_missing'), (int)($rhythm['missing_plans'] ?? 0)],
            [self::tr('bmscalendar_rhythm_blocked'), (int)($rhythm['blocked_plans'] ?? 0)],
            [self::tr('bmscalendar_rhythm_overdue'), (int)($rhythm['overdue_tasks'] ?? 0)],
            [self::tr('bmscalendar_rhythm_decisions'), (int)($rhythm['needs_decision'] ?? 0)],
        ];
        $stathtml='';
        foreach ($stats as $stat) {
            $stathtml .= \html_writer::div(
                \html_writer::span(s($stat[0]), 'bms-calendar-rhythm-stat-label') .
                \html_writer::tag('strong', (string)$stat[1], ['class'=>'bms-calendar-rhythm-stat-value']),
                'bms-calendar-rhythm-stat' . ((int)$stat[1] > 0 ? ' has-attention' : '')
            );
        }
        $actions = (array)($rhythm['actions'] ?? []);
        $buttons='';
        foreach ([
            'launch'=>'bmscalendar_rhythm_launch',
            'meetings'=>'bmscalendar_rhythm_meetings',
            'control'=>'bmscalendar_rhythm_control',
            'readiness'=>'bmscalendar_rhythm_readiness',
            'acceptance'=>'bmscalendar_rhythm_acceptance',
        ] as $key=>$label) {
            if (!empty($actions[$key])) {
                $buttons .= \html_writer::link($actions[$key], self::tr($label), ['class'=>'btn btn-sm btn-outline-secondary']);
            }
        }
        $cycles='';
        foreach ((array)($rhythm['cycles'] ?? []) as $cycle) {
            $typekey='mcycle_template_'.(string)$cycle['template_code'];
            $body=\html_writer::tag('strong', s(self::tr($typekey).' · '.(string)$cycle['title']));
            $body.=\html_writer::div(s((string)$cycle['period_start'].' — '.(string)$cycle['period_end'].' · '.(string)$cycle['status']), 'text-muted small');
            $cycleactions='';
            if (!empty($cycle['actions']['start'])) {
                $url=new \moodle_url('/local/kbacompany/management_cycles.php',['uuid'=>$companyuuid,'cycle'=>$cycle['uuid'],'view'=>'action','action'=>'start']);
                $cycleactions.=\html_writer::link($url,self::tr('bmscalendar_rhythm_start_cycle'),['class'=>'btn btn-sm btn-outline-success']);
            }
            if (!empty($cycle['actions']['close'])) {
                $url=new \moodle_url('/local/kbacompany/management_cycles.php',['uuid'=>$companyuuid,'cycle'=>$cycle['uuid'],'view'=>'action','action'=>'close']);
                $cycleactions.=\html_writer::link($url,self::tr('bmscalendar_rhythm_close_cycle'),['class'=>'btn btn-sm btn-outline-danger']);
            }
            $overview=new \moodle_url('/local/kbacompany/management_cycles.php',['uuid'=>$companyuuid,'cycle'=>$cycle['uuid'],'tab'=>'overview']);
            $body.=\html_writer::div(\html_writer::link($overview,self::tr('bmscalendar_open'),['class'=>'btn btn-sm btn-link']).$cycleactions,'bms-calendar-rhythm-cycle-actions');
            $cycles.=\html_writer::div($body,'bms-calendar-rhythm-cycle');
        }
        $week=(string)($rhythm['week_start'] ?? '').' — '.(string)($rhythm['week_end'] ?? '');
        $head=\html_writer::tag('h3',self::tr('bmscalendar_rhythm_title'),['class'=>'mb-1']);
        $head.=\html_writer::div(self::tr('bmscalendar_rhythm_intro'),'text-muted mb-2');
        $head.=\html_writer::div(self::tr('bmscalendar_rhythm_week').': '.s($week).' · '.self::tr('bmscalendar_rhythm_state').': '.s($state),'bms-calendar-rhythm-week');
        return \html_writer::div($head.\html_writer::div($stathtml,'bms-calendar-rhythm-stats').
            \html_writer::div($buttons,'bms-calendar-rhythm-actions').\html_writer::div($cycles,'bms-calendar-rhythm-cycles'),
            'bms-calendar-rhythm mb-3');
    }

    private static function render_cycle_overview(string $companyuuid, array $model): string {
        $cycles = (array)($model['cycle_periods'] ?? []);
        if (!$cycles) { return ''; }
        $labels = [
            'year'=>self::tr('mcycle_template_year'),
            'quarter'=>self::tr('mcycle_template_quarter'),
            'month'=>self::tr('mcycle_template_month'),
            'week'=>self::tr('mcycle_template_week'),
        ];
        $order = ['year'=>1,'quarter'=>2,'month'=>3,'week'=>4];
        usort($cycles, static function(array $a, array $b) use ($order): int {
            $oa = $order[(string)($a['template_code'] ?? '')] ?? 99;
            $ob = $order[(string)($b['template_code'] ?? '')] ?? 99;
            if ($oa !== $ob) { return $oa <=> $ob; }
            return strcmp((string)$a['period_start'], (string)$b['period_start']);
        });
        $items = '';
        foreach ($cycles as $cycle) {
            $type = (string)($cycle['template_code'] ?? '');
            $label = $labels[$type] ?? $type;
            $url = new \moodle_url('/local/kbacompany/management_cycles.php', [
                'uuid'=>$companyuuid, 'cycle'=>(string)$cycle['uuid'], 'tab'=>'overview'
            ]);
            $period = (string)$cycle['period_start'] . ' — ' . (string)$cycle['period_end'];
            $body = \html_writer::div(s($label), 'bms-calendar-cycle-type');
            $body .= \html_writer::div(s((string)$cycle['title']), 'bms-calendar-cycle-name');
            $body .= \html_writer::div(s($period), 'bms-calendar-cycle-period');
            $body .= \html_writer::span(s((string)$cycle['status']), 'badge badge-light');
            $items .= \html_writer::link($url, $body, ['class'=>'bms-calendar-cycle-card']);
        }
        $head = \html_writer::tag('h3', self::tr('bmscalendar_cycles_title'), ['class'=>'mb-1']);
        $head .= \html_writer::div(self::tr('bmscalendar_cycles_intro'), 'text-muted mb-2');
        return \html_writer::div($head . \html_writer::div($items, 'bms-calendar-cycle-grid'), 'bms-calendar-cycles mb-3');
    }

    private static function render_filters(string $companyuuid, array $model): string {
        $action = new \moodle_url('/local/kbacompany/calendar.php');
        $form = \html_writer::start_tag('form', [
            'method' => 'get', 'action' => $action->out(false), 'class' => 'bms-calendar-filters',
        ]);
        $form .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'uuid', 'value' => $companyuuid]);
        $form .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'scope', 'value' => $model['scope']]);
        $form .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'month', 'value' => $model['month']]);
        $form .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'view', 'value' => $model['view']]);
        $form .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'date', 'value' => $model['anchor_date']]);
        $form .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'member', 'value' => (int)($model['member_id'] ?? 0)]);
        $form .= \html_writer::div(
            \html_writer::tag('label', self::tr('bmscalendar_filter_search'), ['for' => 'bms-calendar-q']) .
            \html_writer::empty_tag('input', [
                'type' => 'search', 'id' => 'bms-calendar-q', 'name' => 'q', 'value' => $model['query'],
                'class' => 'form-control', 'maxlength' => 100,
            ]),
            'bms-calendar-filter-field'
        );
        $options = '';
        foreach ([
            'all' => self::tr('bmscalendar_filter_all'),
            'manual' => self::tr('bmscalendar_filter_manual'),
            'generated' => self::tr('bmscalendar_filter_generated'),
        ] as $value => $label) {
            $attrs = ['value' => $value];
            if ($value === $model['kind']) {
                $attrs['selected'] = 'selected';
            }
            $options .= \html_writer::tag('option', s($label), $attrs);
        }
        $form .= \html_writer::div(
            \html_writer::tag('label', self::tr('bmscalendar_filter_kind'), ['for' => 'bms-calendar-kind']) .
            \html_writer::tag('select', $options, ['id' => 'bms-calendar-kind', 'name' => 'kind', 'class' => 'form-control']),
            'bms-calendar-filter-field'
        );
        $sourceoptions = '';
        foreach ([
            'all'=>self::tr('bmscalendar_source_all'), 'moodle'=>self::tr('bmscalendar_source_moodle'),
            'google'=>self::tr('bms_google_source_label'), 'task'=>self::tr('bmscalendar_source_task'), 'meeting'=>self::tr('bmscalendar_source_meeting'),
            'battleplan'=>self::tr('bmscalendar_source_battleplan'), 'checkpoint'=>self::tr('bmscalendar_source_checkpoint'),
            'cycle'=>self::tr('bmscalendar_source_cycle'),
        ] as $value=>$label) {
            $attrs=['value'=>$value]; if($value===($model['source_filter'] ?? 'all')){$attrs['selected']='selected';}
            $sourceoptions .= \html_writer::tag('option', s($label), $attrs);
        }
        $form .= \html_writer::div(
            \html_writer::tag('label', self::tr('bmscalendar_source_filter'), ['for'=>'bms-calendar-source']) .
            \html_writer::tag('select',$sourceoptions,['id'=>'bms-calendar-source','name'=>'source','class'=>'form-control']),
            'bms-calendar-filter-field'
        );
        $buttons = \html_writer::tag('button', self::tr('bmscalendar_filter_apply'), ['type' => 'submit', 'class' => 'btn btn-success']);
        $reset = new \moodle_url('/local/kbacompany/calendar.php', [
            'uuid' => $companyuuid, 'scope' => $model['scope'], 'month' => $model['month'],
            'view' => $model['view'], 'date' => $model['anchor_date'], 'member'=>(int)($model['member_id'] ?? 0),
        ]);
        $buttons .= \html_writer::link($reset, self::tr('bmscalendar_filter_reset'), ['class' => 'btn btn-outline-secondary']);
        $form .= \html_writer::div($buttons, 'bms-calendar-filter-actions');
        $form .= \html_writer::end_tag('form');
        return $form;
    }

    private static function render_detail(string $companyuuid, array $model, array $event): string {
        $close = new \moodle_url('/local/kbacompany/calendar.php', [
            'uuid' => $companyuuid, 'scope' => $model['scope'], 'month' => $model['month'],
            'q' => $model['query'], 'kind' => $model['kind'], 'source' => $model['source_filter'],
            'view' => $model['view'], 'date' => $model['anchor_date'], 'member'=>(int)($model['member_id'] ?? 0),
        ]);
        $body = \html_writer::div(s($event['name']), 'bms-calendar-detail-name');
        $meta = '';
        $meta .= self::detail_row(self::tr('bmscalendar_detail_when'), $event['date_label'] . ' · ' . $event['time_label']);
        $meta .= self::detail_row(self::tr('bmscalendar_detail_duration'), $event['duration_label']);
        $meta .= self::detail_row(self::tr('bmscalendar_detail_source'), $event['source_label']);
        $meta .= self::detail_row(self::tr('bmscalendar_detail_type'), $event['kind_label']);
        $body .= \html_writer::div($meta, 'bms-calendar-detail-meta');
        $description = trim((string)$event['description']) !== '' ? (string)$event['description'] : self::tr('bmscalendar_no_description');
        $body .= \html_writer::div(
            \html_writer::div(self::tr('bmscalendar_detail_description'), 'bms-calendar-detail-label') .
            \html_writer::div(s($description), 'bms-calendar-detail-description'),
            'bms-calendar-detail-section'
        );
        $actions = \html_writer::link($event['moodle_url'], self::tr('bmscalendar_open_source'), [
            'class' => 'btn btn-outline-secondary',
        ]);
        if (!empty($event['can_manage'])) {
            $edit = new \moodle_url('/local/kbacompany/calendar.php', [
                'uuid' => $companyuuid, 'scope' => $model['scope'], 'month' => $model['month'],
                'q' => $model['query'], 'kind' => $model['kind'], 'source' => $model['source_filter'], 'view' => $model['view'], 'date' => $model['anchor_date'], 'member'=>(int)($model['member_id'] ?? 0), 'action' => 'edit', 'eventid' => (int)$event['id'],
            ]);
            $actions .= \html_writer::link($edit, self::tr('bmscalendar_edit'), ['class' => 'btn btn-success']);
        }
        $actions .= \html_writer::link($close, self::tr('bmscalendar_close'), ['class' => 'btn btn-outline-secondary']);
        $body .= \html_writer::div($actions, 'bms-calendar-detail-actions');
        return \html_writer::div(
            \html_writer::tag('h2', self::tr('bmscalendar_event_details'), ['class' => 'bms-calendar-editor-title']) . $body,
            'bms-calendar-detail'
        );
    }

    private static function detail_row(string $label, string $value): string {
        return \html_writer::div(
            \html_writer::span(s($label), 'bms-calendar-detail-label') .
            \html_writer::span(s($value), 'bms-calendar-detail-value'),
            'bms-calendar-detail-row'
        );
    }

    private static function render_editor(string $companyuuid, array $model, array $editor): string {
        $values = $editor['values'];
        $title = !empty($editor['editing']) ? self::tr('bmscalendar_editor_edit') : self::tr('bmscalendar_editor_new');
        $action = new \moodle_url('/local/kbacompany/calendar.php');
        $form = \html_writer::start_tag('form', [
            'method' => 'post',
            'action' => $action->out(false),
            'class' => 'bms-calendar-editor-form',
        ]);
        $form .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        $form .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'operation', 'value' => 'save']);
        $form .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'uuid', 'value' => $companyuuid]);
        $form .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'scope', 'value' => $model['scope']]);
        $form .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'month', 'value' => $model['month']]);
        $form .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'q', 'value' => $model['query']]);
        $form .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'kind', 'value' => $model['kind']]);
        $form .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'source', 'value' => $model['source_filter']]);
        $form .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'view', 'value' => $model['view']]);
        $form .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'date', 'value' => $model['anchor_date']]);
        $form .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'member', 'value' => (int)($model['member_id'] ?? 0)]);
        $form .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'eventid', 'value' => (int)$values['eventid']]);

        $form .= self::field(self::tr('bmscalendar_field_name'),
            \html_writer::empty_tag('input', [
                'type' => 'text', 'name' => 'name', 'value' => $values['name'], 'maxlength' => 255,
                'class' => 'form-control', 'required' => 'required',
            ]));
        $form .= self::field(self::tr('bmscalendar_field_description'),
            \html_writer::tag('textarea', s($values['description']), [
                'name' => 'description', 'class' => 'form-control', 'rows' => 3,
            ]));
        $form .= self::field(self::tr('bmscalendar_field_start'),
            \html_writer::empty_tag('input', [
                'type' => 'datetime-local', 'name' => 'start', 'value' => $values['start'],
                'class' => 'form-control', 'required' => 'required',
            ]));
        $form .= self::field(self::tr('bmscalendar_field_duration'),
            \html_writer::empty_tag('input', [
                'type' => 'number', 'name' => 'duration', 'value' => (int)$values['duration'],
                'min' => 0, 'max' => 10080, 'class' => 'form-control',
            ]));

        if (empty($editor['editing'])) {
            $repeatoptions = '';
            foreach ([
                'none' => self::tr('bmscalendar_repeat_none'),
                'weekly' => self::tr('bmscalendar_repeat_weekly'),
                'monthly' => self::tr('bmscalendar_repeat_monthly'),
                'weekdays' => self::tr('bmscalendar_repeat_weekdays'),
            ] as $repeatvalue => $repeatlabel) {
                $repeatoptions .= \html_writer::tag('option', s($repeatlabel), ['value' => $repeatvalue]);
            }
            $form .= self::field(self::tr('bmscalendar_repeat'),
                \html_writer::tag('select', $repeatoptions, ['name' => 'repeatmode', 'class' => 'form-control']));
            $form .= self::field(self::tr('bmscalendar_repeat_count'),
                \html_writer::empty_tag('input', [
                    'type' => 'number', 'name' => 'repeatcount', 'value' => 1,
                    'min' => 1, 'max' => 52, 'class' => 'form-control',
                ]));
            $weekdayhtml = '';
            foreach ([
                1=>self::tr('bmscalendar_mon'), 2=>self::tr('bmscalendar_tue'), 3=>self::tr('bmscalendar_wed'),
                4=>self::tr('bmscalendar_thu'), 5=>self::tr('bmscalendar_fri'), 6=>self::tr('bmscalendar_sat'),
                7=>self::tr('bmscalendar_sun'),
            ] as $day=>$label) {
                $checkbox = \html_writer::empty_tag('input', ['type'=>'checkbox','name'=>'repeatweekdays[]','value'=>$day]);
                $weekdayhtml .= \html_writer::tag('label', $checkbox . ' ' . s($label), ['class'=>'mr-3']);
            }
            $form .= self::field(self::tr('bmscalendar_repeat_days'), \html_writer::div($weekdayhtml, 'bms-calendar-repeat-days'));
            $form .= \html_writer::div(self::tr('bmscalendar_repeat_note'), 'small text-muted mb-3');
        }

        if (!empty($editor['editing'])) {
            $form .= self::field(self::tr('bmscalendar_field_target'),
                \html_writer::div(s($values['target_label']), 'form-control-plaintext'));
            $form .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'target', 'value' => $values['target']]);
        } else if (!empty($editor['targets'])) {
            $options = '';
            foreach ($editor['targets'] as $target) {
                $attrs = ['value' => $target['value']];
                if ((string)$target['value'] === (string)$values['target']) {
                    $attrs['selected'] = 'selected';
                }
                $options .= \html_writer::tag('option', s($target['label']), $attrs);
            }
            $form .= self::field(self::tr('bmscalendar_field_target'),
                \html_writer::tag('select', $options, ['name' => 'target', 'class' => 'form-control', 'required' => 'required']));
        } else {
            $form .= \html_writer::div(self::tr('bmscalendar_no_create_targets'), 'alert alert-warning');
        }

        $cancel = new \moodle_url('/local/kbacompany/calendar.php', [
            'uuid' => $companyuuid, 'scope' => $model['scope'], 'month' => $model['month'],
            'q' => $model['query'], 'kind' => $model['kind'], 'source' => $model['source_filter'],
            'view' => $model['view'], 'date' => $model['anchor_date'],
        ]);
        $buttons = \html_writer::tag('button', self::tr('bmscalendar_save'), [
            'type' => 'submit', 'class' => 'btn btn-success',
        ]);
        $buttons .= \html_writer::link($cancel, self::tr('bmscalendar_cancel'), ['class' => 'btn btn-outline-secondary']);
        $form .= \html_writer::div($buttons, 'bms-calendar-editor-actions');
        $form .= \html_writer::end_tag('form');
        return \html_writer::div(
            \html_writer::tag('h2', s($title), ['class' => 'bms-calendar-editor-title']) . $form,
            'bms-calendar-editor'
        );
    }

    private static function field(string $label, string $control): string {
        return \html_writer::div(
            \html_writer::tag('label', s($label), ['class' => 'bms-calendar-editor-label']) . $control,
            'bms-calendar-editor-field'
        );
    }

    private static function event_actions(string $companyuuid, array $model, array $event): string {
        $edit = new \moodle_url('/local/kbacompany/calendar.php', [
            'uuid' => $companyuuid,
            'scope' => $model['scope'],
            'month' => $model['month'],
            'q' => $model['query'],
            'kind' => $model['kind'],
            'source' => $model['source_filter'],
            'view' => $model['view'],
            'date' => $model['anchor_date'],
            'action' => 'edit',
            'eventid' => (int)$event['id'],
        ]);
        $links = \html_writer::link($edit, self::tr('bmscalendar_edit'), ['class' => 'bms-calendar-event-edit']);
        $form = \html_writer::start_tag('form', [
            'method' => 'post',
            'action' => (new \moodle_url('/local/kbacompany/calendar.php'))->out(false),
            'class' => 'bms-calendar-event-delete-form',
            'onsubmit' => "return confirm('" . addslashes_js(self::tr('bmscalendar_delete_confirm')) . "');",
        ]);
        $form .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        $form .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'operation', 'value' => 'delete']);
        $form .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'uuid', 'value' => $companyuuid]);
        $form .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'scope', 'value' => $model['scope']]);
        $form .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'month', 'value' => $model['month']]);
        $form .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'q', 'value' => $model['query']]);
        $form .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'kind', 'value' => $model['kind']]);
        $form .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'source', 'value' => $model['source_filter']]);
        $form .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'view', 'value' => $model['view']]);
        $form .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'date', 'value' => $model['anchor_date']]);
        $form .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'member', 'value' => (int)($model['member_id'] ?? 0)]);
        $form .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'eventid', 'value' => (int)$event['id']]);
        $form .= \html_writer::tag('button', self::tr('bmscalendar_delete'), [
            'type' => 'submit', 'class' => 'bms-calendar-event-delete',
        ]);
        $form .= \html_writer::end_tag('form');
        return \html_writer::div($links . $form, 'bms-calendar-event-actions');
    }

}
