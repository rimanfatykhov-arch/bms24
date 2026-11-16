<?php
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\business_date_service;
use local_kbacompany\local\daily_report_service;
use local_kbacompany\local\daily_report_store_service as store;
use local_kbacompany\local\daily_report_ui_service as reportui;
use local_kbacompany\local\bms_page_shell_service;
use local_kbacompany\local\bms24_shell_service;
use local_kbacompany\local\bms24_ui_kit_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$day = optional_param('day', business_date_service::day(), PARAM_RAW_TRIMMED);
$actor = (int)$USER->id;
// ADR-0009 R1: saving reports is behind a server-side switch; while it is off the page is the R0 read-only summary.
$enabled = store::enabled();
$tab = $enabled ? optional_param('tab', 'my', PARAM_ALPHA) : 'my';
if (!in_array($tab, ['my', 'history'], true)) {
    $tab = 'my';
}
$data = daily_report_service::context($uuid, $actor, $day,
    optional_param('taskpage', 0, PARAM_INT), optional_param('resultpage', 0, PARAM_INT));
$base = ['uuid' => $uuid, 'day' => $day];

// ---- writes: POST + sesskey only; the actor is the session user, never a request parameter.
$posted = null; $error = ''; $conflict = false;
$knownerrors = ['dailyreports_conflict', 'dailyreports_donerequired', 'dailyreports_notdraft', 'dailyreports_futureday',
    'dailyreports_taskunavailable', 'dailyreports_toomanylinks', 'dailyreports_texttoolong', 'dailyreports_busy'];
if ($enabled && $tab === 'my' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    $action = required_param('action', PARAM_ALPHA);
    $values = [];
    foreach (store::FIELDS as $field) {
        $values[$field] = optional_param($field, '', PARAM_RAW);
    }
    $links = optional_param_array('linkedtasks', [], PARAM_ALPHANUMEXT);
    $revision = optional_param('revision', 0, PARAM_INT);
    try {
        if ($action === 'save') {
            store::save_draft($uuid, $actor, $day, $values, $links, $revision);
            redirect(reportui::url($uuid, ['day' => $day, 'notice' => 'saved']));
        } else if ($action === 'submit') {
            store::submit($uuid, $actor, $day, required_param('requestkey', PARAM_ALPHANUMEXT), $revision, $values, $links);
            redirect(reportui::url($uuid, ['day' => $day, 'notice' => 'submitted']));
        }
        throw new moodle_exception('invalidparameter');
    } catch (moodle_exception $e) {
        if (!in_array($e->errorcode, $knownerrors, true)) {
            throw $e;
        }
        // Keep the employee's text in the form; show the saved text next to it after a conflict.
        $posted = ['values' => $values, 'links' => $links];
        $error = get_string($e->errorcode, 'local_kbacompany');
        $conflict = $e->errorcode === 'dailyreports_conflict';
    }
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacompany/daily_report.php', $base + ($tab === 'history' ? ['tab' => 'history'] : [])));
$PAGE->set_title(get_string('dailyreports_nav', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));
if ($enabled && $tab === 'my') {
    $PAGE->requires->js(new moodle_url('/local/kbacompany/js/daily_report_form.js'));
}
$shell = bms_page_shell_service::begin($uuid, $actor, 'dailyreports', get_string('dailyreports_nav', 'local_kbacompany'));
$str = static function(string $key): string { return get_string('dailyreport_' . $key, 'local_kbacompany'); };
$status = static function(string $value): string { return get_string('taskcenter_status_' . $value, 'local_kbacompany'); };
$link = static function(string $taskuuid, string $title) use ($uuid): string {
    return html_writer::link(new moodle_url('/local/kbacompany/tasks.php', ['uuid' => $uuid, 'task' => $taskuuid]), s($title));
};
echo $OUTPUT->heading(get_string('dailyreports_nav', 'local_kbacompany'), 1);
// R0 tab; R1 adds the own history tab only behind the switch. The reviewer tab arrives with R2 and its rights.
$tabs = [['url' => (new moodle_url('/local/kbacompany/daily_report.php', $base))->out(false),
    'label' => get_string('dailyreports_tab_my', 'local_kbacompany'), 'active' => $tab === 'my']];
if ($enabled) {
    $tabs[] = ['url' => reportui::url($uuid, ['tab' => 'history'])->out(false),
        'label' => get_string('dailyreports_tab_history', 'local_kbacompany'), 'active' => $tab === 'history'];
}
$tablabel = get_string('dailyreports_tabs', 'local_kbacompany');
if (bms24_shell_service::is_enabled()) {
    echo bms24_ui_kit_service::fragment('ui_tabs', ['label' => $tablabel, 'items' => $tabs]);
} else {
    echo html_writer::start_tag('nav', ['aria-label' => $tablabel]);
    echo html_writer::start_tag('ul', ['class' => 'nav nav-tabs mb-3']);
    foreach ($tabs as $tabitem) {
        echo html_writer::tag('li', html_writer::link($tabitem['url'], s($tabitem['label']), ['class' => 'nav-link'
            . ($tabitem['active'] ? ' active' : ''), 'aria-current' => $tabitem['active'] ? 'page' : null]), ['class' => 'nav-item']);
    }
    echo html_writer::end_tag('ul');
    echo html_writer::end_tag('nav');
}

if ($tab === 'history') {
    echo reportui::history($uuid, store::history($uuid, $actor, optional_param('page', 0, PARAM_INT)));
    bms_page_shell_service::finish($shell);
    exit;
}

echo html_writer::tag('p', $str('intro'), ['class' => 'text-muted']);
echo html_writer::start_tag('form', ['method' => 'get', 'class' => 'mb-4']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'uuid', 'value' => $uuid]);
echo html_writer::tag('label', $str('day'), ['for' => 'dailyreport-day', 'class' => 'd-block']);
echo html_writer::empty_tag('input', ['type' => 'date', 'id' => 'dailyreport-day', 'name' => 'day',
    'value' => $day, 'required' => 'required', 'max' => business_date_service::day(),
    'class' => 'form-control d-inline-block w-auto mr-2 mb-2']);
echo html_writer::tag('button', $str('show'), ['type' => 'submit', 'class' => 'btn btn-primary mb-2']);
echo html_writer::end_tag('form');

if ($enabled) {
    // ---- the saved report of this day (draft form or frozen versions)
    $notice = optional_param('notice', '', PARAM_ALPHA);
    if ($error === '' && in_array($notice, ['saved', 'submitted'], true)) {
        echo html_writer::div(s(get_string('dailyreports_' . $notice, 'local_kbacompany')), 'alert alert-success',
            ['role' => 'status']);
    }
    $future = strcmp($day, business_date_service::day()) > 0;
    $view = $future ? null : store::view($uuid, $actor, $day);
    $report = $view['report'] ?? null;
    echo html_writer::start_tag('section', ['class' => 'bms-dailyreport mb-4', 'aria-labelledby' => 'dailyreport-own']);
    echo $OUTPUT->heading(s(get_string('dailyreports_own_heading', 'local_kbacompany')) . ' ' .
        reportui::status_badge($report ? (string)$report->status : 'none'), 2, '', 'dailyreport-own');
    if ($error !== '') {
        echo html_writer::div(s($error), 'alert alert-danger', ['role' => 'alert']);
    }
    if ($future) {
        echo html_writer::tag('p', s(get_string('dailyreports_futureday', 'local_kbacompany')));
    } else if (!$report || $view['draft']) {
        $draft = $view['draft'];
        if ($conflict && $draft) {
            echo reportui::conflict($draft, $posted['values']);
        }
        $values = $posted['values'] ?? [];
        if (!$posted && $draft) {
            foreach (store::FIELDS as $field) {
                $values[$field] = (string)$draft->$field;
            }
        }
        $selected = $posted['links'] ?? array_map(static function($l): string { return (string)$l->taskuuid; },
            $view['draftlinks']);
        $candidates = store::link_candidates($uuid, $actor, $data, $view['draftlinks']);
        echo reportui::form($uuid, $day, $values, $selected, $candidates, $draft ? (int)$draft->revision : 0,
            \core\uuid::generate());
    }
    foreach ($view['versions'] ?? [] as $i => $version) {
        $revieweruser = (int)$version->reviewerid > 0 ? core_user::get_user((int)$version->reviewerid) : false;
        $reviewer = $revieweruser ? fullname($revieweruser) : null;
        if ($i === 1) {
            echo $OUTPUT->heading(s(get_string('dailyreports_versions_previous', 'local_kbacompany')), 3);
        }
        echo reportui::version($uuid, $actor, $version, $view['links'][$version->uuid] ?? [], $reviewer,
            $i === 0 && $view['late'], $view['reviews'][$version->uuid] ?? []);
    }
    echo html_writer::end_tag('section');
    // The live summary below is the current state of BMS24 data, not part of any submitted version.
    echo $OUTPUT->heading(s(get_string('dailyreports_daydata', 'local_kbacompany')), 2);
    echo html_writer::div(s(get_string('dailyreports_daydata_note', 'local_kbacompany')), 'alert alert-secondary');
}

echo html_writer::tag('p', s($str('timezone') . ': ' . $data['timezone']));
echo html_writer::div($str('scope'), 'alert alert-info');
$pager = static function(string $kind, int $page, int $total) use ($base): string {
    return $GLOBALS['OUTPUT']->paging_bar($total, $page, daily_report_service::PAGE_SIZE,
        new moodle_url('/local/kbacompany/daily_report.php', $base), $kind . 'page');
};
echo $OUTPUT->heading($str('tasks') . ' (' . $data['tasktotal'] . ')', $enabled ? 3 : 2);
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
echo $OUTPUT->heading($str('results') . ' (' . $data['resulttotal'] . ')', $enabled ? 3 : 2);
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
