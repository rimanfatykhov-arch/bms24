<?php
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\communication_service;
use local_kbacompany\local\company_service;
use local_kbacompany\local\task_service;
use local_kbacompany\local\meeting_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$scope = optional_param('scope', 'inbox', PARAM_ALPHA);
$selected = optional_param('thread', '', PARAM_RAW_TRIMMED);
$convertmessage = optional_param('convert', '', PARAM_RAW_TRIMMED);
company_service::company_context($uuid, (int)$USER->id);

$action = optional_param('action', '', PARAM_ALPHA);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
// RC19 compatibility: old bookmarks land in the canonical Communication Center 2.0 workspace.
if ($action === '' && in_array($method, ['GET', 'HEAD'], true)) {
    $tabmap = ['inbox' => 'inbox', 'unread' => 'unread', 'mentions' => 'mentions', 'all' => 'all'];
    $params = ['uuid' => $uuid, 'tab' => $tabmap[$scope] ?? 'all'];
    if ($selected !== '') { $params['thread'] = $selected; }
    if ($convertmessage !== '') { $params['convert'] = $convertmessage; }
    redirect(new moodle_url('/local/kbacompany/communication_center.php', $params));
}
$error = '';
if ($action !== '') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new moodle_exception('communication_post', 'local_kbacompany');
    }
    require_sesskey();
    try {
        if ($action === 'createthread') {
            $selected = communication_service::create_thread($uuid, (int)$USER->id, [
                'threadtype' => required_param('threadtype', PARAM_ALPHA),
                'title' => optional_param('title', '', PARAM_TEXT),
                'contextkind' => optional_param('contextkind', '', PARAM_ALPHANUMEXT),
                'contextuuid' => optional_param('contextuuid', '', PARAM_RAW_TRIMMED),
                'contextrevision' => optional_param('contextrevision', 0, PARAM_INT),
                'participants' => optional_param_array('participants', [], PARAM_INT),
            ]);
        } else if ($action === 'send') {
            $selected = required_param('thread', PARAM_RAW_TRIMMED);
            communication_service::send_message_with_attachment($uuid, (int)$USER->id, $selected,
                optional_param('body', '', PARAM_RAW), $_FILES['attachment'] ?? null,
                optional_param_array('mentions', [], PARAM_INT), optional_param('replytouuid', '', PARAM_RAW_TRIMMED));
        } else if ($action === 'addparticipant') {
            $selected = required_param('thread', PARAM_RAW_TRIMMED);
            communication_service::add_participant($uuid, (int)$USER->id, $selected,
                required_param('participantid', PARAM_INT));
        } else if ($action === 'readnotification') {
            communication_service::mark_notification_read($uuid, (int)$USER->id,
                required_param('notification', PARAM_RAW_TRIMMED));
        } else if ($action === 'createdecision') {
            $selected = required_param('thread', PARAM_RAW_TRIMMED);
            $messageuuid = required_param('messageuuid', PARAM_RAW_TRIMMED);
            communication_service::create_decision_from_message($uuid, (int)$USER->id, $messageuuid);
            redirect(new moodle_url('/local/kbacompany/communications.php', [
                'uuid' => $uuid, 'scope' => $scope, 'thread' => $selected,
            ]), get_string('communication_decisioncreated', 'local_kbacompany'));
        } else if ($action === 'createtask') {
            $selected = required_param('thread', PARAM_RAW_TRIMMED);
            $messageuuid = required_param('messageuuid', PARAM_RAW_TRIMMED);
            $taskuuid = communication_service::create_task_from_message($uuid, (int)$USER->id, $messageuuid, [
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
            redirect(new moodle_url('/local/kbacompany/tasks.php', ['uuid' => $uuid, 'task' => $taskuuid]),
                get_string('communication_taskcreated', 'local_kbacompany'));
        }
        redirect(new moodle_url('/local/kbacompany/communications.php', [
            'uuid' => $uuid, 'scope' => $scope, 'thread' => $selected,
        ]), get_string('communication_saved', 'local_kbacompany'));
    } catch (moodle_exception $e) {
        if ($e->module !== 'local_kbacompany') { throw $e; }
        $error = $e->getMessage();
    }
}

$data = communication_service::context($uuid, (int)$USER->id, $selected, $scope);
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacompany/communications.php', [
    'uuid' => $uuid, 'scope' => $scope, 'thread' => $selected,
]));
// BMS page shell adapter selects the presentation layout.
$PAGE->set_title(get_string('communication_title', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));

$hidden = static function(string $action, string $thread = '') use ($uuid, $scope): string {
    $html = html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'uuid', 'value' => $uuid]);
    $html .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'scope', 'value' => $scope]);
    $html .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => $action]);
    $html .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    if ($thread !== '') {
        $html .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'thread', 'value' => $thread]);
    }
    return $html;
};
$input = static function(string $name, string $value = '', string $type = 'text', bool $required = false): string {
    $attrs = ['type' => $type, 'name' => $name, 'value' => $value, 'class' => 'form-control'];
    if ($required) { $attrs['required'] = 'required'; }
    return html_writer::empty_tag('input', $attrs);
};
$textarea = static function(string $name, string $value = '', bool $required = false, int $rows = 3): string {
    $attrs = ['name' => $name, 'rows' => $rows, 'class' => 'form-control'];
    if ($required) { $attrs['required'] = 'required'; }
    return html_writer::tag('textarea', s($value), $attrs);
};

$employees = $data['employees'];
$people = [];
foreach ($employees as $id => $name) {
    if ((int)$id !== (int)$USER->id) { $people[(int)$id] = $name; }
}

$streamoptions = [];
foreach (task_service::STREAMS as $stream) {
    $streamoptions[$stream] = get_string('taskcenter_stream_' . $stream, 'local_kbacompany');
}
$priorityoptions = [];
foreach (task_service::PRIORITIES as $priority) {
    $priorityoptions[$priority] = get_string('taskcenter_priority_' . $priority, 'local_kbacompany');
}

// Current user ownership in the selected thread is explicit; company management role alone does not grant private access.
$isthreadowner = false;
if ($data['selected']) {
    foreach ($data['selected']['participants'] as $participant) {
        if ((int)$participant['user_id'] === (int)$USER->id && $participant['role'] === 'owner') {
            $isthreadowner = true;
            break;
        }
    }
}

$bmsshell = \local_kbacompany\local\bms_page_shell_service::begin($uuid, (int)$USER->id, 'communications', get_string('communication_title', 'local_kbacompany'));
echo $OUTPUT->heading(get_string('communication_title', 'local_kbacompany'));
echo html_writer::tag('p', get_string('communication_intro', 'local_kbacompany'), ['class' => 'lead']);
echo html_writer::tag('p', get_string('communication_rule', 'local_kbacompany'), ['class' => 'alert alert-info']);
if ($error !== '') { echo $OUTPUT->notification(s($error), 'error'); }

echo html_writer::link(new moodle_url('/local/kbacompany/tasks.php', ['uuid' => $uuid]),
    get_string('taskcenter_title', 'local_kbacompany'), ['class' => 'btn btn-outline-primary mr-2']);
echo html_writer::link(new moodle_url('/local/kbacompany/bms_workspace.php', ['uuid' => $uuid]),
    get_string('backtocompany', 'local_kbacompany'), ['class' => 'btn btn-link']);

$summary = get_string('communication_unreadthreads', 'local_kbacompany') . ': ' . (int)$data['counts']['unread_threads'] .
    ' · ' . get_string('communication_notifications', 'local_kbacompany') . ': ' . (int)$data['counts']['unread_notifications'] .
    ' · ' . get_string('communication_mentions', 'local_kbacompany') . ': ' . (int)$data['counts']['mentions'];
echo html_writer::tag('p', s($summary), ['class' => 'mt-3']);

foreach (['inbox', 'unread', 'mentions', 'all'] as $item) {
    $class = $data['scope'] === $item ? 'btn btn-secondary mr-1' : 'btn btn-outline-secondary mr-1';
    echo html_writer::link(new moodle_url('/local/kbacompany/communications.php', ['uuid' => $uuid, 'scope' => $item]),
        get_string('communication_scope_' . $item, 'local_kbacompany'), ['class' => $class]);
}

echo $OUTPUT->heading(get_string('communication_threads', 'local_kbacompany'), 3);
$table = new html_table();
$table->head = [get_string('communication_thread', 'local_kbacompany'), get_string('communication_type', 'local_kbacompany'),
    get_string('communication_context', 'local_kbacompany'), get_string('communication_unread', 'local_kbacompany'),
    get_string('communication_updated', 'local_kbacompany')];
foreach ($data['threads'] as $thread) {
    $link = html_writer::link(new moodle_url('/local/kbacompany/communications.php', [
        'uuid' => $uuid, 'scope' => $data['scope'], 'thread' => $thread['uuid'],
    ]), s($thread['title']));
    $contextlabel = '—';
    if ($thread['context_kind'] !== '') {
        $contextkey = 'communication_object_' . $thread['context_kind'];
        $contextname = get_string_manager()->string_exists($contextkey, 'local_kbacompany') ?
            get_string($contextkey, 'local_kbacompany') : $thread['context_kind'];
        $contextlabel = $contextname . ': ' . $thread['context_uuid'];
    }
    $table->data[] = [$link, get_string('communication_type_' . $thread['thread_type'], 'local_kbacompany'),
        s($contextlabel), (int)$thread['unread_count'], $thread['last_message_at'] ? userdate($thread['last_message_at']) : '—'];
}
echo $data['threads'] ? html_writer::table($table) : $OUTPUT->notification(get_string('communication_empty', 'local_kbacompany'), 'info');

if ($data['selected']) {
    $thread = $data['selected'];
    echo $OUTPUT->heading(s($thread['title']), 3);
    if ($thread['context_kind'] !== '') {
        $contextkey = 'communication_object_' . $thread['context_kind'];
        $contextname = get_string_manager()->string_exists($contextkey, 'local_kbacompany') ?
            get_string($contextkey, 'local_kbacompany') : $thread['context_kind'];
        echo html_writer::tag('p', get_string('communication_context', 'local_kbacompany') . ': ' .
            s($contextname . ' / ' . $thread['context_uuid']) .
            ($thread['context_revision'] ? ' @' . (int)$thread['context_revision'] : ''));
        $contexturl = null;
        if ($thread['context_kind'] === 'meeting') {
            $contexturl = new moodle_url('/local/kbacompany/meetings.php', ['uuid' => $uuid, 'meeting' => $thread['context_uuid']]);
        } else if ($thread['context_kind'] === 'task') {
            $contexturl = new moodle_url('/local/kbacompany/tasks.php', ['uuid' => $uuid, 'task' => $thread['context_uuid']]);
        } else if ($thread['context_kind'] === 'statistic') {
            $contexturl = new moodle_url('/local/kbacompany/indicators.php', ['uuid' => $uuid, 'stat' => $thread['context_uuid'], 'tab' => 'all']);
        }
        if ($contexturl) {
            echo html_writer::link($contexturl, get_string('communication_opencontext', 'local_kbacompany'),
                ['class' => 'btn btn-sm btn-outline-secondary mb-2']);
        }
    }
    $participantnames = array_map(static function(array $p): string { return $p['name']; }, $thread['participants']);
    echo html_writer::tag('p', get_string('communication_participants', 'local_kbacompany') . ': ' . s(implode(', ', $participantnames)));

    if ($thread['context_kind'] !== 'meeting') {
        echo html_writer::link(new moodle_url('/local/kbacompany/meetings.php', [
            'uuid' => $uuid, 'source_thread' => $thread['uuid'],
        ]), get_string('meeting_create_from_discussion', 'local_kbacompany'),
            ['class' => 'btn btn-sm btn-outline-primary mb-3']);
    }

    if ($isthreadowner && $thread['thread_type'] !== 'direct') {
        $existingids = array_map(static function(array $p): int { return (int)$p['user_id']; }, $thread['participants']);
        $addpeople = array_diff_key($people, array_flip($existingids));
        if ($addpeople) {
            echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'form-inline mb-3']);
            echo $hidden('addparticipant', $thread['uuid']);
            echo html_writer::select($addpeople, 'participantid', '', false, ['class' => 'form-control mr-2']);
            echo html_writer::tag('button', get_string('communication_addparticipant', 'local_kbacompany'),
                ['type' => 'submit', 'class' => 'btn btn-outline-primary']);
            echo html_writer::end_tag('form');
        }
    }

    echo $OUTPUT->heading(get_string($thread['thread_type'] === 'object' ? 'communication_history' : 'communication_messages', 'local_kbacompany'), 4);
    foreach ($data['messages'] as $message) {
        $body = $message['status'] === 'active' ? format_text($message['body'], FORMAT_PLAIN) :
            html_writer::tag('em', get_string('communication_messagewithdrawn', 'local_kbacompany'));
        $meta = s($message['author_name']) . ' · ' . userdate($message['time_created']);
        echo html_writer::start_tag('div', ['class' => 'border rounded p-2 mb-2']);
        echo html_writer::tag('div', $meta, ['class' => 'small text-muted']);
        echo $body;
        if (!empty($message['mentions'])) {
            $mentioned = [];
            foreach ($message['mentions'] as $mentionedid) {
                if (isset($employees[(int)$mentionedid])) { $mentioned[] = '@' . $employees[(int)$mentionedid]; }
            }
            if ($mentioned) {
                echo html_writer::div(s(implode(', ', $mentioned)), 'small text-primary mt-1');
            }
        }
        if (!empty($message['attachments'])) {
            $attachmenthtml = '';
            foreach ($message['attachments'] as $attachment) {
                $label = $attachment['label'] !== '' ? $attachment['label'] : $attachment['value'];
                if (!empty($attachment['url'])) {
                    $attachmenthtml .= html_writer::link($attachment['url'], s($label),
                        ['class' => 'btn btn-sm btn-outline-secondary mr-1 mb-1']);
                } else {
                    $attachmenthtml .= html_writer::span(s($label), 'badge badge-light mr-1');
                }
            }
            echo html_writer::div($attachmenthtml, 'mt-2');
        }
        if ($message['task_uuid'] !== '') {
            echo html_writer::tag('div', html_writer::link(new moodle_url('/local/kbacompany/tasks.php', [
                'uuid' => $uuid, 'task' => $message['task_uuid'],
            ]), get_string('communication_opentask', 'local_kbacompany')), ['class' => 'small mt-1']);
        } else if ($message['status'] === 'active') {
            echo html_writer::tag('div', html_writer::link(new moodle_url('/local/kbacompany/communications.php', [
                'uuid' => $uuid, 'scope' => $data['scope'], 'thread' => $thread['uuid'], 'convert' => $message['uuid'],
            ]), get_string('communication_createtask', 'local_kbacompany')), ['class' => 'small mt-1']);
        }
        if ($message['status'] === 'active' && $thread['context_kind'] === 'meeting' && $thread['context_uuid'] !== '') {
            echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'd-inline-block mt-1 ml-1']);
            echo $hidden('createdecision', $thread['uuid']);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'messageuuid', 'value' => $message['uuid']]);
            echo html_writer::tag('button', get_string('communication_createdecision', 'local_kbacompany'),
                ['type' => 'submit', 'class' => 'btn btn-sm btn-outline-primary']);
            echo html_writer::end_tag('form');
        }
        echo html_writer::end_tag('div');
    }

    echo html_writer::start_tag('form', ['method' => 'post', 'enctype' => 'multipart/form-data', 'class' => 'kbacompany-form border rounded p-3 mb-3']);
    echo $hidden('send', $thread['uuid']);
    echo html_writer::label(get_string('communication_message', 'local_kbacompany'), 'communication_body');
    echo $textarea('body', '', false, 4); // text or a file (or both); the server rejects an empty message
    $mentionoptions = [];
    foreach ($thread['participants'] as $participant) {
        if ((int)$participant['user_id'] !== (int)$USER->id) {
            $mentionoptions[(int)$participant['user_id']] = $participant['name'];
        }
    }
    if ($mentionoptions) {
        echo html_writer::label(get_string('communication_mentions', 'local_kbacompany'), 'communication_mentions');
        echo html_writer::select($mentionoptions, 'mentions[]', [], false,
            ['id' => 'communication_mentions', 'class' => 'form-control', 'multiple' => 'multiple']);
        echo html_writer::div(get_string('communication_mentions_help', 'local_kbacompany'), 'small text-muted mb-2');
    }
    echo html_writer::label(get_string('communication_attachment', 'local_kbacompany'), 'communication_attachment');
    echo html_writer::empty_tag('input', ['type' => 'file', 'name' => 'attachment', 'id' => 'communication_attachment',
        'class' => 'form-control-file mb-2']);
    echo html_writer::tag('button', get_string('communication_send', 'local_kbacompany'),
        ['type' => 'submit', 'class' => 'btn btn-primary mt-2']);
    echo html_writer::end_tag('form');

    if ($convertmessage !== '') {
        $message = null;
        foreach ($data['messages'] as $candidate) {
            if ($candidate['uuid'] === $convertmessage) { $message = $candidate; break; }
        }
        if ($message && $message['status'] === 'active' && $message['task_uuid'] === '') {
            echo $OUTPUT->heading(get_string('communication_taskfrommessage', 'local_kbacompany'), 4);
            echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'kbacompany-form border rounded p-3 mb-3']);
            echo $hidden('createtask', $thread['uuid']);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'messageuuid', 'value' => $message['uuid']]);
            echo html_writer::label(get_string('taskcenter_task', 'local_kbacompany'), 'communication_task_title');
            echo $input('title', core_text::substr($message['body'], 0, 120), 'text', true);
            echo html_writer::label(get_string('taskcenter_product', 'local_kbacompany'), 'communication_task_product');
            echo $textarea('product', '', true);
            echo html_writer::label(get_string('taskcenter_description', 'local_kbacompany'), 'communication_task_description');
            echo $textarea('description', $message['body']);
            echo html_writer::label(get_string('taskcenter_assignee', 'local_kbacompany'), 'communication_task_assignee');
            echo html_writer::select($employees, 'assigneeid', (int)$USER->id, false, ['class' => 'form-control']);
            $reviewers = [0 => get_string('taskcenter_noreviewer', 'local_kbacompany')] + $employees;
            echo html_writer::label(get_string('taskcenter_reviewer', 'local_kbacompany'), 'communication_task_reviewer');
            echo html_writer::select($reviewers, 'reviewerid', 0, false, ['class' => 'form-control']);
            echo html_writer::label(get_string('taskcenter_stream', 'local_kbacompany'), 'communication_task_stream');
            echo html_writer::select($streamoptions, 'stream', 'operate', false, ['class' => 'form-control']);
            echo html_writer::label(get_string('taskcenter_priority', 'local_kbacompany'), 'communication_task_priority');
            echo html_writer::select($priorityoptions, 'priority', 'normal', false, ['class' => 'form-control']);
            echo html_writer::label(get_string('taskcenter_duedate', 'local_kbacompany'), 'communication_task_due');
            echo $input('duedate', '', 'date');
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'startdate', 'value' => '']);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'planminutes', 'value' => '0']);
            echo html_writer::tag('button', get_string('communication_createtask', 'local_kbacompany'),
                ['type' => 'submit', 'class' => 'btn btn-success mt-2']);
            echo html_writer::end_tag('form');
        }
    }
}

echo $OUTPUT->heading(get_string('communication_notifications', 'local_kbacompany'), 3);
$notificationtable = new html_table();
$notificationtable->head = [get_string('communication_notification', 'local_kbacompany'),
    get_string('communication_updated', 'local_kbacompany'), get_string('communication_actions', 'local_kbacompany')];
foreach ($data['notifications'] as $notification) {
    $label = get_string('communication_notification_' . $notification['kind'], 'local_kbacompany') . ': ' . $notification['title'];
    $actionhtml = '';
    if ($notification['thread_uuid'] !== '') {
        $actionhtml .= html_writer::link(new moodle_url('/local/kbacompany/communications.php', [
            'uuid' => $uuid, 'thread' => $notification['thread_uuid'],
        ]), get_string('communication_open', 'local_kbacompany'), ['class' => 'btn btn-sm btn-outline-primary mr-1']);
    }
    if (!$notification['is_read']) {
        $actionhtml .= html_writer::start_tag('form', ['method' => 'post', 'class' => 'd-inline']);
        $actionhtml .= $hidden('readnotification');
        $actionhtml .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'notification', 'value' => $notification['uuid']]);
        $actionhtml .= html_writer::tag('button', get_string('communication_markread', 'local_kbacompany'),
            ['type' => 'submit', 'class' => 'btn btn-sm btn-outline-secondary']);
        $actionhtml .= html_writer::end_tag('form');
    }
    $notificationtable->data[] = [s($label), userdate($notification['time_created']), $actionhtml];
}
echo $data['notifications'] ? html_writer::table($notificationtable) :
    $OUTPUT->notification(get_string('communication_nonotifications', 'local_kbacompany'), 'info');

echo $OUTPUT->heading(get_string('communication_newthread', 'local_kbacompany'), 3);
echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'kbacompany-form border rounded p-3']);
echo $hidden('createthread');
$types = [];
foreach (communication_service::THREAD_TYPES as $type) {
    $types[$type] = get_string('communication_type_' . $type, 'local_kbacompany');
}
echo html_writer::label(get_string('communication_type', 'local_kbacompany'), 'communication_threadtype');
echo html_writer::select($types, 'threadtype', 'direct', false, ['id' => 'communication_threadtype', 'class' => 'form-control']);
echo html_writer::label(get_string('communication_thread', 'local_kbacompany'), 'communication_title');
echo $input('title');
$contexts = ['' => '—'];
foreach (communication_service::CONTEXT_KINDS as $kind) {
    $contexts[$kind] = get_string('communication_object_' . $kind, 'local_kbacompany');
}
echo html_writer::label(get_string('communication_contextkind', 'local_kbacompany'), 'communication_contextkind');
echo html_writer::select($contexts, 'contextkind', '', false, ['id' => 'communication_contextkind', 'class' => 'form-control']);
echo html_writer::label(get_string('communication_contextuuid', 'local_kbacompany'), 'communication_contextuuid');
echo $input('contextuuid');
echo html_writer::label(get_string('communication_contextrevision', 'local_kbacompany'), 'communication_contextrevision');
echo $input('contextrevision', '0', 'number');
echo html_writer::tag('div', get_string('communication_participanthelp', 'local_kbacompany'), ['class' => 'small text-muted mt-2']);
foreach ($people as $id => $name) {
    $checkbox = html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => 'participants[]', 'value' => $id, 'id' => 'comm_part_' . $id]);
    echo html_writer::tag('div', $checkbox . ' ' . html_writer::label(s($name), 'comm_part_' . $id), ['class' => 'form-check']);
}
echo html_writer::tag('button', get_string('communication_create', 'local_kbacompany'),
    ['type' => 'submit', 'class' => 'btn btn-primary mt-2']);
echo html_writer::end_tag('form');

\local_kbacompany\local\bms_page_shell_service::finish($bmsshell);
