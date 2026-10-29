<?php
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\calendar_notification_preferences_service as prefs;
use local_kbacompany\local\company_context_service;
use local_kbacompany\local\permission_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$company = company_context_service::context($uuid, (int)$USER->id);
$canmanage = permission_service::can_manage_organization($uuid, (int)$USER->id);

function local_kbacompany_calnotify_text(string $key): string {
    $fallbackru = [
        'calendar_notify_title'=>'Уведомления календаря','calendar_notify_intro'=>'Настройте напоминания BMS24. Каналы popup/email управляются штатными настройками уведомлений учебной платформы.',
        'calendar_notify_personal'=>'Мои настройки','calendar_notify_company'=>'Политика компании','calendar_notify_use_company'=>'Использовать настройки компании',
        'calendar_notify_enabled'=>'Уведомления BMS24 включены','calendar_notify_meeting'=>'Напоминать о встречах','calendar_notify_meeting_lead'=>'За сколько минут до встречи',
        'calendar_notify_digest'=>'Утренняя сводка дня','calendar_notify_morning_hour'=>'Час утренних уведомлений','calendar_notify_task_due'=>'Напоминать о задачах со сроком сегодня',
        'calendar_notify_checkpoint'=>'Напоминать о контрольных точках на завтра','calendar_notify_quiet'=>'Не беспокоить','calendar_notify_quiet_start'=>'Начало тихого режима',
        'calendar_notify_quiet_end'=>'Окончание тихого режима','calendar_notify_save'=>'Сохранить','calendar_notify_reset'=>'Вернуть настройки компании',
        'calendar_notify_saved'=>'Настройки уведомлений сохранены.','calendar_notify_company_saved'=>'Политика уведомлений компании сохранена.',
        'calendar_notify_reset_done'=>'Личные настройки сброшены. Используется политика компании.','calendar_notify_effective'=>'Сейчас действует',
        'calendar_notify_effective_company'=>'политика компании','calendar_notify_effective_personal'=>'личная настройка','calendar_notify_moodle_channels'=>'Настроить popup / email для обучения',
        'calendar_notify_quiet_help'=>'Во время тихого режима новые календарные напоминания не отправляются. Бизнес-объекты не изменяются.',
        'calendar_notify_back'=>'Вернуться в календарь',
    ];
    $fallbacken = [
        'calendar_notify_title'=>'Calendar notifications','calendar_notify_intro'=>'Configure BMS24 reminders. Popup/email channels are controlled by learning-platform notification preferences.',
        'calendar_notify_personal'=>'My settings','calendar_notify_company'=>'Company policy','calendar_notify_use_company'=>'Use company settings',
        'calendar_notify_enabled'=>'BMS24 notifications enabled','calendar_notify_meeting'=>'Meeting reminders','calendar_notify_meeting_lead'=>'Minutes before meeting',
        'calendar_notify_digest'=>'Morning day summary','calendar_notify_morning_hour'=>'Morning notification hour','calendar_notify_task_due'=>'Remind about tasks due today',
        'calendar_notify_checkpoint'=>'Remind about tomorrow checkpoints','calendar_notify_quiet'=>'Do not disturb','calendar_notify_quiet_start'=>'Quiet time starts',
        'calendar_notify_quiet_end'=>'Quiet time ends','calendar_notify_save'=>'Save','calendar_notify_reset'=>'Restore company settings',
        'calendar_notify_saved'=>'Notification settings saved.','calendar_notify_company_saved'=>'Company notification policy saved.',
        'calendar_notify_reset_done'=>'Personal settings reset. Company policy is now used.','calendar_notify_effective'=>'Currently using',
        'calendar_notify_effective_company'=>'company policy','calendar_notify_effective_personal'=>'personal settings','calendar_notify_moodle_channels'=>'Configure popup / email for learning',
        'calendar_notify_quiet_help'=>'During quiet hours new calendar reminders are suppressed. Business objects are not changed.',
        'calendar_notify_back'=>'Back to calendar',
    ];
    $sm = get_string_manager();
    if ($sm->string_exists($key, 'local_kbacompany')) {
        $value = get_string($key, 'local_kbacompany');
        if (strpos($value, '[[') !== 0) { return $value; }
    }
    return current_language() === 'ru' ? ($fallbackru[$key] ?? $key) : ($fallbacken[$key] ?? $key);
}

function local_kbacompany_calnotify_request(): array {
    return [
        'enabled'=>optional_param('enabled', 0, PARAM_BOOL),
        'meeting_enabled'=>optional_param('meeting_enabled', 0, PARAM_BOOL),
        'meeting_lead'=>optional_param('meeting_lead', 60, PARAM_INT),
        'morning_digest'=>optional_param('morning_digest', 0, PARAM_BOOL),
        'morning_hour'=>optional_param('morning_hour', 8, PARAM_INT),
        'task_due'=>optional_param('task_due', 0, PARAM_BOOL),
        'checkpoint_tomorrow'=>optional_param('checkpoint_tomorrow', 0, PARAM_BOOL),
        'quiet_enabled'=>optional_param('quiet_enabled', 0, PARAM_BOOL),
        'quiet_start'=>optional_param('quiet_start', '22:00', PARAM_RAW_TRIMMED),
        'quiet_end'=>optional_param('quiet_end', '07:00', PARAM_RAW_TRIMMED),
    ];
}

$url = new moodle_url('/local/kbacompany/calendar_notifications.php', ['uuid'=>$uuid]);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    $operation = required_param('operation', PARAM_ALPHAEXT);
    try {
        if ($operation === 'personal_save') {
            prefs::save_personal($uuid, (int)$USER->id, (bool)optional_param('use_company', 0, PARAM_BOOL), local_kbacompany_calnotify_request());
            redirect($url, local_kbacompany_calnotify_text('calendar_notify_saved'), null, \core\output\notification::NOTIFY_SUCCESS);
        } else if ($operation === 'personal_reset') {
            prefs::reset_personal($uuid, (int)$USER->id);
            redirect($url, local_kbacompany_calnotify_text('calendar_notify_reset_done'), null, \core\output\notification::NOTIFY_SUCCESS);
        } else if ($operation === 'company_save') {
            if (!$canmanage) { throw new moodle_exception('nopermissions', 'error'); }
            prefs::save_company_defaults($uuid, (int)$USER->id, local_kbacompany_calnotify_request());
            redirect($url, local_kbacompany_calnotify_text('calendar_notify_company_saved'), null, \core\output\notification::NOTIFY_SUCCESS);
        }
        throw new invalid_parameter_exception('Unknown operation.');
    } catch (\Throwable $e) {
        redirect($url, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
}

$personal = prefs::personal_state($uuid, (int)$USER->id);
$companysettings = prefs::company_defaults($uuid, (int)$USER->id);
$effective = prefs::effective($uuid, (int)$USER->id);

$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_title(local_kbacompany_calnotify_text('calendar_notify_title'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));
$shell = \local_kbacompany\local\bms_page_shell_service::begin($uuid, (int)$USER->id, 'settings', local_kbacompany_calnotify_text('calendar_notify_title'));

echo html_writer::tag('p', local_kbacompany_calnotify_text('calendar_notify_intro'), ['class'=>'text-muted']);
echo html_writer::div(local_kbacompany_calnotify_text('calendar_notify_effective') . ': <strong>' .
    local_kbacompany_calnotify_text($effective['source'] === 'company' ? 'calendar_notify_effective_company' : 'calendar_notify_effective_personal') . '</strong>', 'alert alert-info');

$renderform = static function(string $operation, array $s, bool $usecompany, bool $companyform) use ($uuid): string {
    $out = html_writer::start_tag('form', ['method'=>'post']);
    $out .= html_writer::empty_tag('input', ['type'=>'hidden','name'=>'sesskey','value'=>sesskey()]);
    $out .= html_writer::empty_tag('input', ['type'=>'hidden','name'=>'operation','value'=>$operation]);
    if (!$companyform) {
        $out .= html_writer::div(html_writer::checkbox('use_company', 1, $usecompany, local_kbacompany_calnotify_text('calendar_notify_use_company')), 'mb-3');
    }
    $checks = [
        'enabled'=>'calendar_notify_enabled','meeting_enabled'=>'calendar_notify_meeting','morning_digest'=>'calendar_notify_digest',
        'task_due'=>'calendar_notify_task_due','checkpoint_tomorrow'=>'calendar_notify_checkpoint','quiet_enabled'=>'calendar_notify_quiet',
    ];
    foreach ($checks as $name=>$label) { $out .= html_writer::div(html_writer::checkbox($name, 1, !empty($s[$name]), local_kbacompany_calnotify_text($label)), 'mb-2'); }
    $out .= html_writer::start_div('form-row mt-3');
    $out .= html_writer::start_div('form-group col-md-4');
    $out .= html_writer::label(local_kbacompany_calnotify_text('calendar_notify_meeting_lead'), 'meeting_lead');
    $opts=[]; foreach (prefs::meeting_leads() as $v) { $opts[$v]=$v; }
    $out .= html_writer::select($opts, 'meeting_lead', (int)$s['meeting_lead'], false, ['class'=>'form-control']);
    $out .= html_writer::end_div();
    $out .= html_writer::start_div('form-group col-md-4');
    $out .= html_writer::label(local_kbacompany_calnotify_text('calendar_notify_morning_hour'), 'morning_hour');
    $hours=[]; foreach (prefs::morning_hours() as $v) { $hours[$v]=sprintf('%02d:00',$v); }
    $out .= html_writer::select($hours, 'morning_hour', (int)$s['morning_hour'], false, ['class'=>'form-control']);
    $out .= html_writer::end_div();
    foreach (['quiet_start'=>'calendar_notify_quiet_start','quiet_end'=>'calendar_notify_quiet_end'] as $name=>$label) {
        $out .= html_writer::start_div('form-group col-md-2');
        $out .= html_writer::label(local_kbacompany_calnotify_text($label), $name);
        $out .= html_writer::empty_tag('input', ['type'=>'time','name'=>$name,'id'=>$name,'value'=>s((string)$s[$name]),'class'=>'form-control']);
        $out .= html_writer::end_div();
    }
    $out .= html_writer::end_div();
    $out .= html_writer::tag('p', local_kbacompany_calnotify_text('calendar_notify_quiet_help'), ['class'=>'small text-muted']);
    $out .= html_writer::tag('button', local_kbacompany_calnotify_text('calendar_notify_save'), ['type'=>'submit','class'=>'btn btn-primary']);
    $out .= html_writer::end_tag('form');
    return $out;
};

echo html_writer::start_div('row');
echo html_writer::start_div($canmanage ? 'col-lg-6 mb-3' : 'col-12 mb-3');
echo html_writer::start_div('card card-body h-100');
echo html_writer::tag('h2', local_kbacompany_calnotify_text('calendar_notify_personal'), ['class'=>'h4']);
echo $renderform('personal_save', $personal['settings'], $personal['use_company'], false);
echo html_writer::start_tag('form', ['method'=>'post','class'=>'mt-2']);
echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'sesskey','value'=>sesskey()]);
echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'operation','value'=>'personal_reset']);
echo html_writer::tag('button', local_kbacompany_calnotify_text('calendar_notify_reset'), ['type'=>'submit','class'=>'btn btn-outline-secondary']);
echo html_writer::end_tag('form');
echo html_writer::end_div(); echo html_writer::end_div();

if ($canmanage) {
    echo html_writer::start_div('col-lg-6 mb-3'); echo html_writer::start_div('card card-body h-100');
    echo html_writer::tag('h2', local_kbacompany_calnotify_text('calendar_notify_company'), ['class'=>'h4']);
    echo $renderform('company_save', $companysettings, false, true);
    echo html_writer::end_div(); echo html_writer::end_div();
}
echo html_writer::end_div();

echo html_writer::link(new moodle_url('/message/notificationpreferences.php'), local_kbacompany_calnotify_text('calendar_notify_moodle_channels'), ['class'=>'btn btn-outline-primary mr-2']);
echo html_writer::link(new moodle_url('/local/kbacompany/calendar.php', ['uuid'=>$uuid]), local_kbacompany_calnotify_text('calendar_notify_back'), ['class'=>'btn btn-outline-secondary']);
\local_kbacompany\local\bms_page_shell_service::finish($shell);
