<?php
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\business_date_service;
use local_kbacompany\local\daily_report_service;
use local_kbacompany\local\bms_page_shell_service;
use local_kbacompany\local\bms24_shell_service;
use local_kbacompany\local\bms24_ui_kit_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$day = optional_param('day', business_date_service::day(), PARAM_RAW_TRIMMED);
$data = daily_report_service::context($uuid, (int)$USER->id, $day,
    optional_param('taskpage', 0, PARAM_INT), optional_param('resultpage', 0, PARAM_INT));
$base = ['uuid' => $uuid, 'day' => $day];
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacompany/daily_report.php', $base));
$PAGE->set_title(get_string('dailyreports_nav', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));
$shell = bms_page_shell_service::begin($uuid, (int)$USER->id, 'dailyreports', get_string('dailyreports_nav', 'local_kbacompany'));
$str = static function(string $key): string { return get_string('dailyreport_' . $key, 'local_kbacompany'); };
$status = static function(string $value): string { return get_string('taskcenter_status_' . $value, 'local_kbacompany'); };
$link = static function(string $taskuuid, string $title) use ($uuid): string {
    return html_writer::link(new moodle_url('/local/kbacompany/tasks.php', ['uuid' => $uuid, 'task' => $taskuuid]), s($title));
};
echo $OUTPUT->heading(get_string('dailyreports_nav', 'local_kbacompany'), 1);
// R0: one tab today. History and the reviewer tab appear only when R1/R2 add them and the user has rights.
$tabs = [['url' => (new moodle_url('/local/kbacompany/daily_report.php', $base))->out(false),
    'label' => get_string('dailyreports_tab_my', 'local_kbacompany'), 'active' => true]];
$tablabel = get_string('dailyreports_tabs', 'local_kbacompany');
if (bms24_shell_service::is_enabled()) {
    echo bms24_ui_kit_service::fragment('ui_tabs', ['label' => $tablabel, 'items' => $tabs]);
} else {
    echo html_writer::start_tag('nav', ['aria-label' => $tablabel]);
    echo html_writer::start_tag('ul', ['class' => 'nav nav-tabs mb-3']);
    foreach ($tabs as $tab) {
        echo html_writer::tag('li', html_writer::link($tab['url'], s($tab['label']), ['class' => 'nav-link'
            . ($tab['active'] ? ' active' : ''), 'aria-current' => $tab['active'] ? 'page' : null]), ['class' => 'nav-item']);
    }
    echo html_writer::end_tag('ul');
    echo html_writer::end_tag('nav');
}
echo html_writer::tag('p', $str('intro'), ['class' => 'text-muted']);
echo html_writer::start_tag('form', ['method' => 'get', 'class' => 'mb-4']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'uuid', 'value' => $uuid]);
echo html_writer::tag('label', $str('day'), ['for' => 'dailyreport-day', 'class' => 'd-block']);
echo html_writer::empty_tag('input', ['type' => 'date', 'id' => 'dailyreport-day', 'name' => 'day',
    'value' => $day, 'required' => 'required', 'class' => 'form-control d-inline-block w-auto mr-2 mb-2']);
echo html_writer::tag('button', $str('show'), ['type' => 'submit', 'class' => 'btn btn-primary mb-2']);
echo html_writer::end_tag('form');
echo html_writer::tag('p', s($str('timezone') . ': ' . $data['timezone']));
echo html_writer::div($str('scope'), 'alert alert-info');
$pager = static function(string $kind, int $page, int $total) use ($base): string {
    return $GLOBALS['OUTPUT']->paging_bar($total, $page, daily_report_service::PAGE_SIZE,
        new moodle_url('/local/kbacompany/daily_report.php', $base), $kind . 'page');
};
echo $OUTPUT->heading($str('tasks') . ' (' . $data['tasktotal'] . ')', 2);
echo html_writer::tag('p', $str('tasksnote'), ['class' => 'text-muted']);
if (!$data['tasks']) { echo html_writer::tag('p', $str('emptytasks')); }
foreach ($data['tasks'] as $task) {
    echo html_writer::start_tag('article', ['class' => 'border-bottom py-3']);
    echo html_writer::tag('h3', $link($task->uuid, $task->title), ['class' => 'h5']);
    echo html_writer::tag('p', s($str('currentstatus') . ': ' . $status($task->status)));
    if ((int)$task->factminutes > 0) {
        echo html_writer::tag('p', s($str('tasktime') . ': ' . $task->factminutes . ' ' . $str('minutes')));
    }
    echo html_writer::end_tag('article');
}
echo $pager('task', $data['taskpage'], $data['tasktotal']);
echo $OUTPUT->heading($str('results') . ' (' . $data['resulttotal'] . ')', 2);
echo html_writer::tag('p', $str('resultsnote'), ['class' => 'text-muted']);
if (!$data['results']) { echo html_writer::tag('p', $str('emptyresults')); }
foreach ($data['results'] as $event) {
    echo html_writer::start_tag('article', ['class' => 'border-bottom py-3']);
    echo html_writer::tag('h3', $link($event->taskuuid, $event->title), ['class' => 'h5']);
    echo html_writer::tag('p', s(userdate($event->timecreated) . ' · ' . $str('submittedstatus') . ': ' . $status($event->newstatus)));
    echo html_writer::tag('p', nl2br(s($event->result !== '' ? $event->result : $str('missingresult'))));
    echo html_writer::end_tag('article');
}
echo $pager('result', $data['resultpage'], $data['resulttotal']);
bms_page_shell_service::finish($shell);
