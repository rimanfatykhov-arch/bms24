<?php
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\battle_plan_discussion_service;
use local_kbacompany\local\communication_center_service;
use local_kbacompany\local\communication_live_service;
use local_kbacompany\local\communication_center_ui_service as ui;
use local_kbacompany\local\communication_object_service;
use local_kbacompany\local\communication_service;
use local_kbacompany\local\company_context_service;
use local_kbacompany\local\business_date_service;
use local_kbacompany\local\daily_report_discussion_service;
use local_kbacompany\local\daily_report_feed_service;
use local_kbacompany\local\daily_report_store_service;
use local_kbacompany\local\task_ui_service;
use local_kbacompany\local\task_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$actor = (int)$USER->id;
company_context_service::context($uuid, $actor);

// #145: a link without a section opens the remembered section or the one with content (resolved below).
$tabparam = optional_param('tab', '', PARAM_ALPHA);
$tab = $tabparam;
$filter = optional_param('filter', 'all', PARAM_ALPHA);
if (!in_array($filter, ['all', 'unread', 'mentions'], true)) { $filter = 'all'; }
// Preserve legacy meeting URLs and their selected canonical thread.
if ($tab === 'meetings') { $tab = 'discussions'; }
if (in_array($tab, ['inbox', 'unread', 'mentions'], true)) {
    $filter = $tab === 'mentions' ? 'mentions' : 'unread';
    $tab = 'messages';
}
if ($tab === 'all') { $tab = 'messages'; }
$selected = optional_param('thread', '', PARAM_RAW_TRIMMED);
$convertmessage = optional_param('convert', '', PARAM_RAW_TRIMMED);
$q = optional_param('q', '', PARAM_RAW_TRIMMED);
$mq = optional_param('mq', '', PARAM_RAW_TRIMMED);
$objectkind = optional_param('objectkind', '', PARAM_ALPHANUMEXT);
$objectuuid = optional_param('objectuuid', '', PARAM_RAW_TRIMMED);
$objectrevision = optional_param('objectrevision', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) {
    throw new moodle_exception('communication_post', 'local_kbacompany');
}

// Compatibility navigation only. Task Center remains the canonical task workspace.
if ($tab === 'tasks' && $method !== 'POST' && $action === '') {
    $scope = task_service::normalise_scope(optional_param('taskscope', 'my', PARAM_ALPHA),
        task_service::can_manage($uuid, $actor));
    $provider = optional_param('provider', 'all', PARAM_ALPHANUMEXT);
    $params = ['scope' => $scope];
    if (in_array($provider, ['legacy', 'kbaelement'], true)) {
        $params += ['view' => 'sources', 'provider' => $provider];
    }
    redirect(task_ui_service::url($uuid, $params));
}
$allowedtabs = ['all', 'inbox', 'unread', 'messages', 'discussions', 'mentions', 'meetings', 'notifications', 'dayfeed'];
if ($tab !== '' && !in_array($tab, $allowedtabs, true)) { $tab = 'messages'; }
// Day feed -> report draft: the section exists only while saved daily reports are switched on (server-side switch).
$dayfeedenabled = daily_report_store_service::enabled();
if ($tab === 'dayfeed' && !$dayfeedenabled) { $tab = 'messages'; }

$error = '';
if ($action !== '') {
    if ($method !== 'POST') { throw new moodle_exception('communication_post', 'local_kbacompany'); }
    require_sesskey();
    try {
        if ($action === 'createthread') {
            $threadtype = required_param('threadtype', PARAM_ALPHA);
            if (!in_array($threadtype, ['direct', 'group'], true)) {
                throw new moodle_exception('communication_invalid', 'local_kbacompany');
            }
            $selected = communication_service::create_thread($uuid, $actor, [
                'threadtype' => $threadtype,
                'title' => optional_param('title', '', PARAM_TEXT),
                'participants' => optional_param_array('participants', [], PARAM_INT),
            ]);
            $tab = 'messages';
        } else if ($action === 'createobject') {
            $postkind = required_param('objectkind', PARAM_ALPHANUMEXT);
            $postuuid = required_param('objectuuid', PARAM_RAW_TRIMMED);
            $postrevision = optional_param('objectrevision', 0, PARAM_INT);
            // Reading a task discussion is open to task viewers; joining to write needs the task write right.
            if ($postkind === 'task' && !task_service::can_post_discussion_for($uuid, $actor, $postuuid)) {
                throw new moodle_exception('communication_permission', 'local_kbacompany');
            }
            // The same for a Battle Plan discussion (Q9): reading follows the plan, joining needs the plan write right.
            if ($postkind === 'battleplan' &&
                    !battle_plan_discussion_service::can_post_for($uuid, $actor, $postuuid)) {
                throw new moodle_exception('communication_permission', 'local_kbacompany');
            }
            // R3: a daily report discussion is read by report readers; only the author and current reviewer join.
            if ($postkind === 'dailyreport' &&
                    !daily_report_discussion_service::can_post_for($uuid, $actor, $postuuid)) {
                throw new moodle_exception('communication_permission', 'local_kbacompany');
            }
            // create_thread resolves the canonical object and is also the safe, idempotent join operation.
            $selected = communication_service::create_thread($uuid, $actor, [
                'threadtype' => 'object', 'title' => '', 'contextkind' => $postkind,
                'contextuuid' => $postuuid, 'contextrevision' => $postrevision, 'participants' => [],
            ]);
            $tab = 'discussions';
            $objectkind = $postkind; $objectuuid = $postuuid; $objectrevision = $postrevision;
        } else if ($action === 'send') {
            $selected = required_param('thread', PARAM_RAW_TRIMMED);
            // Text, text + file, or file only; message and file are stored together or not at all.
            communication_service::send_message_with_attachment($uuid, $actor, $selected,
                optional_param('body', '', PARAM_RAW), $_FILES['attachment'] ?? null,
                optional_param_array('mentions', [], PARAM_INT), optional_param('replytouuid', '', PARAM_RAW_TRIMMED));
        } else if ($action === 'editmessage' || $action === 'withdrawmessage') {
            if ($action === 'withdrawmessage' && required_param('confirmwithdraw', PARAM_INT) !== 1) {
                throw new moodle_exception('communication_invalid', 'local_kbacompany');
            }
            $selected = required_param('thread', PARAM_RAW_TRIMMED);
            communication_service::change_message($uuid, $actor, $selected,
                required_param('messageuuid', PARAM_RAW_TRIMMED),
                $action === 'editmessage' ? 'edit' : 'withdraw',
                $action === 'editmessage' ? required_param('body', PARAM_RAW) : '',
                required_param('expectedhash', PARAM_ALPHANUMEXT));
        } else if ($action === 'addparticipant') {
            $selected = required_param('thread', PARAM_RAW_TRIMMED);
            communication_service::add_participant($uuid, $actor, $selected, required_param('participantid', PARAM_INT));
        } else if ($action === 'removeparticipant') {
            $selected = required_param('thread', PARAM_RAW_TRIMMED);
            communication_service::remove_participant($uuid, $actor, $selected, required_param('participantid', PARAM_INT));
        } else if ($action === 'leavethread') {
            communication_service::leave_thread($uuid, $actor, required_param('thread', PARAM_RAW_TRIMMED));
            $selected = '';
        } else if ($action === 'readnotification') {
            communication_service::mark_notification_read($uuid, $actor,
                required_param('notification', PARAM_RAW_TRIMMED));
            $tab = 'notifications';
        } else if ($action === 'readallnotifications') {
            // #145: "Mark all as read" marks only the actor's own notifications shown by the canonical projection;
            // each one goes through the same service call as the single action.
            foreach (communication_service::context($uuid, $actor, '', 'all')['notifications'] as $notification) {
                if (empty($notification['is_read'])) {
                    communication_service::mark_notification_read($uuid, $actor, (string)$notification['uuid']);
                }
            }
            $tab = 'notifications';
        } else if ($action === 'createdecision') {
            $selected = required_param('thread', PARAM_RAW_TRIMMED);
            communication_service::create_decision_from_message($uuid, $actor,
                required_param('messageuuid', PARAM_RAW_TRIMMED));
        } else if ($action === 'createtask') {
            $selected = required_param('thread', PARAM_RAW_TRIMMED);
            $taskuuid = communication_service::create_task_from_message($uuid, $actor,
                required_param('messageuuid', PARAM_RAW_TRIMMED), [
                    'title' => required_param('title', PARAM_TEXT),
                    'description' => optional_param('description', '', PARAM_RAW),
                    'product' => required_param('product', PARAM_RAW),
                    'assigneeid' => required_param('assigneeid', PARAM_INT),
                    'reviewerid' => optional_param('reviewerid', 0, PARAM_INT),
                    'priority' => required_param('priority', PARAM_ALPHA),
                    'stream' => required_param('stream', PARAM_ALPHA),
                    'startdate' => optional_param('startdate', '', PARAM_RAW_TRIMMED),
                    'duedate' => optional_param('duedate', '', PARAM_RAW_TRIMMED),
                    'planminutes' => optional_param('planminutes', 0, PARAM_INT),
                    'parentuuid' => '',
                ]);
            redirect(task_ui_service::url($uuid, ['task' => $taskuuid]),
                get_string('communication_taskcreated', 'local_kbacompany'));
        } else {
            throw new moodle_exception('communication_invalid', 'local_kbacompany');
        }
        redirect(ui::url($uuid, ['tab' => $tab, 'thread' => $selected, 'filter' => $filter]),
            get_string('communication_saved', 'local_kbacompany'));
    } catch (moodle_exception $e) {
        if ($e->module !== 'local_kbacompany') { throw $e; }
        $error = $e->getMessage();
    }
}

// A canonical object card may deep-link here. GET never joins/creates a discussion.
$objectrequest = null;
if ($objectkind !== '' || $objectuuid !== '') {
    try {
        $objectrequest = communication_object_service::describe($uuid, $actor, $objectkind, $objectuuid, $objectrevision);
        global $DB;
        $existing = $DB->get_record('local_kbacompany_commthread', [
            'companyuuid' => $uuid, 'threadtype' => 'object', 'contextkind' => $objectkind,
            'contextuuid' => $objectuuid, 'status' => 'active',
        ], 'uuid');
        if ($existing && (in_array($objectkind, communication_service::VIEWER_READ_KINDS, true) ||
                $DB->record_exists('local_kbacompany_commpart', [
                'companyuuid' => $uuid, 'threaduuid' => (string)$existing->uuid, 'userid' => $actor, 'active' => 1]))) {
            // Task viewers read the one canonical discussion; the service re-checks object access.
            $selected = (string)$existing->uuid;
        }
        $tab = 'discussions';
    } catch (moodle_exception $e) {
        if ($e->module !== 'local_kbacompany') { throw $e; }
        $objectrequest = null;
        $selected = '';
        $error = get_string('communication_contextunavailable', 'local_kbacompany');
    }
}

// #145: a selected thread always opens in its own section; without a section and thread, use the default rule.
if ($selected !== '' && $tab !== 'notifications') {
    $threadtype = $DB->get_field('local_kbacompany_commthread', 'threadtype', ['companyuuid' => $uuid, 'uuid' => $selected]);
    if ($threadtype !== false) { $tab = communication_center_service::section_of((string)$threadtype); }
}
if ($tab === '') {
    // No stored preference (no new personal data): open the first section that has content.
    $tab = communication_center_service::default_tab(communication_center_service::section_counts($uuid, $actor));
}
if ($tab === 'notifications' || $tab === 'dayfeed') { $selected = ''; $convertmessage = ''; }
try {
    // The day feed shows no conversation: the workspace is loaded like the notifications section (counters, people).
    $data = communication_center_service::workspace_context($uuid, $actor, $tab === 'dayfeed' ? 'notifications' : $tab,
        $selected, $q, $mq, optional_param('mpage', 0, PARAM_INT), $filter);
} catch (moodle_exception $e) {
    if ($e->module !== 'local_kbacompany') { throw $e; }
    // Do not leak whether a thread/object exists after access was revoked.
    $selected = '';
    $convertmessage = '';
    $error = get_string('communication_contextunavailable', 'local_kbacompany');
    $data = communication_center_service::workspace_context($uuid, $actor, $tab, '', $q, '', 0, $filter);
}

$data['dayfeed_enabled'] = $dayfeedenabled;
$feedday = '';
if ($tab === 'dayfeed') {
    // Own submitted task results of one civil day in the employee's timezone (today by default).
    $data['tab'] = 'dayfeed';
    $feedday = optional_param('day', business_date_service::day(), PARAM_RAW_TRIMMED);
    try {
        $data['dayfeed'] = daily_report_feed_service::day($uuid, $actor, $feedday,
            max(0, min(100000, optional_param('fpage', 0, PARAM_INT))));
    } catch (moodle_exception $e) {
        if ($e->errorcode !== 'dailyreport_invaliddate') { throw $e; }
        $error = get_string('dailyreport_invaliddate', 'local_kbacompany');
        $feedday = business_date_service::day();
        $data['dayfeed'] = daily_report_feed_service::day($uuid, $actor, $feedday);
    }
    // Results can be added only to the own draft of a past or current day; never to a sent report.
    $data['dayfeed']['today'] = business_date_service::day();
    $data['dayfeed']['sent'] = false;
    $data['dayfeed']['canadd'] = false;
    if (strcmp($feedday, $data['dayfeed']['today']) <= 0) {
        $reportview = daily_report_store_service::view($uuid, $actor, $feedday);
        $data['dayfeed']['sent'] = $reportview['report'] && !$reportview['draft'];
        $data['dayfeed']['canadd'] = !$data['dayfeed']['sent'];
    }
}
$data['message_search'] = $mq;
$data['reply'] = optional_param('reply', '', PARAM_ALPHANUMEXT);
$data['message_page'] = optional_param('mpage', 0, PARAM_INT);
$PAGE->set_context($context);
$PAGE->set_url(ui::url($uuid, ['tab' => $tab, 'thread' => $selected] + ($feedday !== '' ? ['day' => $feedday] : [])));
$PAGE->set_title(get_string('communicationcenter_title', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));
$PAGE->add_body_class('bms-comm-page');
$PAGE->requires->css(new moodle_url('/local/kbacompany/styles.css'));
$PAGE->requires->css(new moodle_url('/local/kbacompany/bms_ui/communication_center.css'));
// The centre renders bms24-* markup; without this sheet those components have no rules.
$PAGE->requires->js_call_amd('local_kbacompany/communication_live_ui', 'init');
$shell = \local_kbacompany\local\bms_page_shell_service::begin($uuid, $actor, 'communications',
    get_string('communicationcenter_title', 'local_kbacompany'));

// Minimal read-only token for live updates. Failure must not break the messaging UI.
$livestate = null;
try {
    $livestate = communication_live_service::snapshot($uuid, $actor, $selected);
} catch (moodle_exception $e) {
    $livestate = null;
}
$liveurl = new moodle_url('/local/kbacompany/communication_live_poll.php', [
    'uuid' => $uuid,
    'thread' => $selected,
]);
echo html_writer::start_div('bms-tui bms-comm2 bms-comm2-shell', [
    'data-send-error' => get_string('bmscomm3_senderror', 'local_kbacompany'),
    'data-live-url' => $livestate ? $liveurl->out(false) : '',
    'data-live-revision' => $livestate['revision'] ?? '',
    'data-live-new' => get_string('bmscomm4_newmessages', 'local_kbacompany'),
    'data-live-unread' => (int)($data['counts']['unread_threads'] ?? 0),
    'data-live-notifications' => (int)($data['counts']['unread_notifications'] ?? 0),
]);
// Marker prevents the generic workspace quick navigation from being injected above the messenger.
echo html_writer::div('', 'bms-context-quicknav bms-comm2-skipnav', ['hidden' => 'hidden', 'aria-hidden' => 'true']);
if ($error !== '') { echo html_writer::div(s($error), 'alert alert-danger', ['role' => 'alert']); }
echo ui::workspace($uuid, $data, $objectrequest, $convertmessage);
echo html_writer::end_div();
\local_kbacompany\local\bms_page_shell_service::finish($shell);
