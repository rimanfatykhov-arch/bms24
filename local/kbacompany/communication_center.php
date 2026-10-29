<?php
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\communication_center_service;
use local_kbacompany\local\communication_live_service;
use local_kbacompany\local\communication_center_ui_service as ui;
use local_kbacompany\local\communication_object_service;
use local_kbacompany\local\communication_service;
use local_kbacompany\local\company_context_service;
use local_kbacompany\local\task_ui_service;
use local_kbacompany\local\task_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$actor = (int)$USER->id;
company_context_service::context($uuid, $actor);

$tab = optional_param('tab', 'inbox', PARAM_ALPHA);
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
$allowedtabs = ['all', 'inbox', 'unread', 'messages', 'discussions', 'mentions', 'meetings', 'notifications'];
if (!in_array($tab, $allowedtabs, true)) { $tab = 'inbox'; }

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
            // create_thread resolves the canonical object and is also the safe, idempotent join operation.
            $selected = communication_service::create_thread($uuid, $actor, [
                'threadtype' => 'object', 'title' => '', 'contextkind' => $postkind,
                'contextuuid' => $postuuid, 'contextrevision' => $postrevision, 'participants' => [],
            ]);
            $tab = 'discussions';
            $objectkind = $postkind; $objectuuid = $postuuid; $objectrevision = $postrevision;
        } else if ($action === 'send') {
            $selected = required_param('thread', PARAM_RAW_TRIMMED);
            $messageuuid = communication_service::send_message($uuid, $actor, $selected,
                required_param('body', PARAM_RAW), optional_param_array('mentions', [], PARAM_INT),
                optional_param('replytouuid', '', PARAM_RAW_TRIMMED));
            if (!empty($_FILES['attachment']) &&
                    (int)($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                communication_service::add_uploaded_attachment($uuid, $actor, $messageuuid, $_FILES['attachment']);
            }
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
        } else if ($action === 'readnotification') {
            communication_service::mark_notification_read($uuid, $actor,
                required_param('notification', PARAM_RAW_TRIMMED));
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
        redirect(ui::url($uuid, ['tab' => $tab, 'thread' => $selected]),
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
        if ($existing && $DB->record_exists('local_kbacompany_commpart', [
                'companyuuid' => $uuid, 'threaduuid' => (string)$existing->uuid, 'userid' => $actor, 'active' => 1])) {
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

try {
    $data = communication_center_service::workspace_context($uuid, $actor, $tab, $selected, $q,
        $mq, optional_param('mpage', 0, PARAM_INT));
} catch (moodle_exception $e) {
    if ($e->module !== 'local_kbacompany') { throw $e; }
    // Do not leak whether a thread/object exists after access was revoked.
    $selected = '';
    $convertmessage = '';
    $error = get_string('communication_contextunavailable', 'local_kbacompany');
    $data = communication_center_service::workspace_context($uuid, $actor, $tab, '', $q);
}

$data['message_search'] = $mq;
$data['message_page'] = optional_param('mpage', 0, PARAM_INT);
$PAGE->set_context($context);
$PAGE->set_url(ui::url($uuid, ['tab' => $tab, 'thread' => $selected]));
$PAGE->set_title(get_string('communicationcenter_title', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));
$PAGE->add_body_class('bms-comm-page');
$PAGE->requires->css(new moodle_url('/local/kbacompany/styles.css'));
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
