<?php
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\calendar_workspace_service;
use local_kbacompany\local\calendar_workspace_ui_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$month = optional_param('month', '', PARAM_ALPHANUMEXT);
$scope = optional_param('scope', 'my', PARAM_ALPHA);
$action = optional_param('action', '', PARAM_ALPHA);
$eventid = optional_param('eventid', 0, PARAM_INT);
$q = trim(optional_param('q', '', PARAM_TEXT));
$kind = optional_param('kind', 'all', PARAM_ALPHA);
$sourcefilter = optional_param('source', 'all', PARAM_ALPHA);
$date = optional_param('date', '', PARAM_RAW_TRIMMED);
$memberid = optional_param('member', 0, PARAM_INT);
$view = optional_param('view', 'month', PARAM_ALPHA);
$find = optional_param('find', 0, PARAM_BOOL);
$finddate = optional_param('finddate', '', PARAM_RAW_TRIMMED);
$findduration = optional_param('findduration', 60, PARAM_INT);
$findmembers = optional_param_array('findmembers', [], PARAM_INT);
$view = in_array($view, ['month', 'week', 'day', 'analytics'], true) ? $view : 'month';
$scope = ($scope === 'company') ? 'company' : 'my';
$kind = in_array($kind, ['all', 'manual', 'generated'], true) ? $kind : 'all';
$sourcefilter = in_array($sourcefilter, ['all','moodle','google','task','meeting','battleplan','checkpoint','cycle'], true) ? $sourcefilter : 'all';
if (!in_array($sourcefilter, ['all','moodle'], true)) { $kind = 'all'; }

$baseurl = new moodle_url('/local/kbacompany/calendar.php', [
    'uuid' => $uuid,
    'month' => $month,
    'scope' => $scope,
    'q' => $q,
    'kind' => $kind,
    'source' => $sourcefilter,
    'view' => $view,
    'date' => $date,
    'member' => $memberid,
]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    $operation = required_param('operation', PARAM_ALPHA);
    try {
        if ($operation === 'save') {
            calendar_workspace_service::save_event($uuid, (int)$USER->id, $scope, [
                'eventid' => optional_param('eventid', 0, PARAM_INT),
                'name' => required_param('name', PARAM_TEXT),
                'description' => optional_param('description', '', PARAM_TEXT),
                'start' => required_param('start', PARAM_RAW_TRIMMED),
                'duration' => optional_param('duration', 0, PARAM_INT),
                'target' => optional_param('target', '', PARAM_RAW_TRIMMED),
                'repeatmode' => optional_param('repeatmode', 'none', PARAM_ALPHA),
                'repeatcount' => optional_param('repeatcount', 1, PARAM_INT),
                'repeatweekdays' => optional_param_array('repeatweekdays', [], PARAM_INT),
            ]);
            redirect($baseurl, calendar_workspace_ui_service::text('bmscalendar_saved'), null,
                \core\output\notification::NOTIFY_SUCCESS);
        } else if ($operation === 'move') {
            $moveid = required_param('eventid', PARAM_INT);
            $targetdate = required_param('targetdate', PARAM_RAW_TRIMMED);
            calendar_workspace_service::move_event($uuid, (int)$USER->id, $scope, $moveid, $targetdate);
            redirect($baseurl, calendar_workspace_ui_service::text('bmscalendar_moved'), null,
                \core\output\notification::NOTIFY_SUCCESS);
        } else if ($operation === 'delete') {
            $deleteid = required_param('eventid', PARAM_INT);
            calendar_workspace_service::delete_event($uuid, (int)$USER->id, $scope, $deleteid);
            redirect($baseurl, calendar_workspace_ui_service::text('bmscalendar_deleted'), null,
                \core\output\notification::NOTIFY_SUCCESS);
        } else {
            throw new invalid_parameter_exception('Unknown calendar operation.');
        }
    } catch (\Throwable $e) {
        redirect($baseurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
}

$model = calendar_workspace_service::view_model($uuid, (int)$USER->id, $month, $scope, $q, $kind, $sourcefilter, $view, $date, $memberid);
$model['meeting_finder'] = calendar_workspace_service::meeting_finder_model(
    $uuid, (int)$USER->id, $scope, $finddate !== '' ? $finddate : $model['anchor_date'],
    $find ? $findmembers : [(int)$USER->id], $findduration
);
$model['meeting_finder']['submitted'] = (bool)$find;
$editor = null;
$detail = null;
if ($action === 'new' || ($action === 'edit' && $eventid > 0)) {
    try {
        $editor = calendar_workspace_service::editor_model(
            $uuid,
            (int)$USER->id,
            $scope,
            $model['month'],
            $action,
            $eventid,
            $date
        );
    } catch (\Throwable $e) {
        redirect($baseurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
}


if ($action === 'view' && $eventid > 0) {
    try {
        $detail = calendar_workspace_service::detail_model(
            $uuid,
            (int)$USER->id,
            $scope,
            $model['month'],
            $eventid
        );
    } catch (\Throwable $e) {
        redirect($baseurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacompany/calendar.php', [
    'uuid' => $uuid,
    'month' => $model['month'],
    'scope' => $model['scope'],
    'q' => $q,
    'kind' => $kind,
    'source' => $sourcefilter,
    'view' => $model['view'],
    'date' => $model['anchor_date'],
    'member' => $model['member_id'],
]));
$PAGE->set_title(calendar_workspace_ui_service::text('bmscalendar_title'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));
$PAGE->requires->js_call_amd('local_kbacompany/calendar_dragdrop', 'init');

$shell = \local_kbacompany\local\bms_page_shell_service::begin(
    $uuid,
    (int)$USER->id,
    'calendar',
    calendar_workspace_ui_service::text('bmscalendar_title')
);

echo html_writer::div(html_writer::link(new moodle_url('/local/kbacompany/calendar_notifications.php', ['uuid'=>$uuid]),
    calendar_workspace_ui_service::text('calendar_notify_title'), ['class'=>'btn btn-outline-secondary btn-sm']), 'text-right mb-2');

echo calendar_workspace_ui_service::render($uuid, $model, $editor, $detail);
\local_kbacompany\local\bms_page_shell_service::finish($shell);
