<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Communication Center 2.0 presentation over the canonical communication service. */
class communication_center_ui_service {
    public static function url(string $company, array $params = []): \moodle_url {
        return new \moodle_url('/local/kbacompany/communication_center.php', ['uuid' => $company] + $params);
    }

    private static function tr(string $key, $a = null): string {
        return get_string($key, 'local_kbacompany', $a);
    }

    private static function hidden(array $values): string {
        return task_ui_service::hidden($values);
    }

    private static function button(string $label, string $class = 'btn btn-primary'): string {
        return \html_writer::tag('button', s($label), ['type' => 'submit', 'class' => $class]);
    }

    private static function post_base(string $company, string $action, string $thread = ''): string {
        $values = ['uuid' => $company, 'action' => $action, 'sesskey' => sesskey()];
        if ($thread !== '') { $values['thread'] = $thread; }
        return self::hidden($values);
    }

    private static function person_options(array $employees, int $exclude = 0): array {
        $out = [];
        foreach ($employees as $id => $name) {
            if ((int)$id !== $exclude) { $out[(int)$id] = $name; }
        }
        return $out;
    }

    /** Main three-pane BMS24 communication workspace. */
    public static function workspace(string $company, array $data, ?array $objectrequest = null,
            string $convertmessage = ''): string {
        global $USER;
        $tab = (string)$data['tab'];
        $selected = $data['selected'];
        $actions = task_ui_service::link(task_ui_service::url($company), self::tr('taskcenter_title'));
        $html = bms_workspace_ux_service::record_header(self::tr('communicationcenter_title'),
            self::tr('communicationcenter_intro'), '', '', $actions);

        $tabs = [];
        foreach (['all', 'inbox', 'unread', 'messages', 'discussions', 'mentions', 'notifications', 'meetings'] as $key) {
            $tabs[$key] = ['label' => self::tr('communicationcenter_tab_' . $key),
                'url' => self::url($company, ['tab' => $key])];
        }
        $html .= bms_workspace_ux_service::action_bar('', bms_workspace_ux_service::view_switcher($tabs, $tab));

        $stats = \html_writer::div(
            \html_writer::tag('span', s(self::tr('communication_unreadthreads') . ': ' . (int)$data['counts']['unread_threads']),
                ['class' => 'bms-comm2-stat']) .
            \html_writer::tag('span', s(self::tr('communication_mentions') . ': ' . (int)$data['counts']['mentions']),
                ['class' => 'bms-comm2-stat']) .
            \html_writer::tag('span', s(self::tr('communication_notifications') . ': ' . (int)$data['counts']['unread_notifications']),
                ['class' => 'bms-comm2-stat']), 'bms-comm2-stats');
        $html .= $stats;

        $left = self::thread_sidebar($company, $data);
        $centre = self::feed($company, $data, $convertmessage);
        $right = self::context_panel($company, $data, $objectrequest, (int)$USER->id, $convertmessage);
        return $html . \html_writer::div($left . $centre . $right, 'bms-comm2-layout');
    }

    private static function thread_sidebar(string $company, array $data): string {
        $tab = (string)$data['tab'];
        $search = (string)$data['search'];
        $form = self::hidden(['uuid' => $company, 'tab' => $tab]);
        $form .= \html_writer::label(s(self::tr('tui_search')), 'bms_comm2_search', false, ['class' => 'sr-only']);
        $form .= \html_writer::empty_tag('input', ['type' => 'search', 'id' => 'bms_comm2_search', 'name' => 'q',
            'value' => $search, 'class' => 'form-control', 'placeholder' => self::tr('tui_search')]);
        $form .= self::button(self::tr('tui_apply'), 'btn btn-outline-secondary');
        $html = \html_writer::tag('form', $form, ['method' => 'get', 'class' => 'bms-comm2-search']);

        if ($tab === 'meetings') {
            $html .= task_ui_service::note(self::tr('communicationcenter_meetings_help'));
            $html .= task_ui_service::link(new \moodle_url('/local/kbacompany/meetings.php', ['uuid' => $company]),
                self::tr('meeting_open_center'), true);
            return \html_writer::tag('aside', $html, ['class' => 'bms-comm2-panel bms-comm2-sidebar']);
        }
        if ($tab === 'notifications') {
            $html .= task_ui_service::note(self::tr('communicationcenter_notifications_help'));
            return \html_writer::tag('aside', $html, ['class' => 'bms-comm2-panel bms-comm2-sidebar']);
        }

        $items = '';
        foreach ($data['threads'] as $thread) {
            $classes = 'bms-comm2-thread';
            if (!empty($data['selected']) && $data['selected']['uuid'] === $thread['uuid']) { $classes .= ' is-active'; }
            $meta = '';
            if (!empty($thread['context'])) {
                $meta = $thread['context']['label'] . ' · ' . $thread['context']['title'];
            } else {
                $meta = self::tr($thread['thread_type'] === 'direct' ? 'communication_type_direct' : 'communication_type_group');
            }
            $badge = (int)$thread['unread_count'] > 0 ?
                \html_writer::tag('span', (string)(int)$thread['unread_count'], ['class' => 'badge badge-primary']) : '';
            $body = \html_writer::div(\html_writer::tag('strong', s($thread['title'])) . $badge, 'bms-comm2-thread-title') .
                \html_writer::div(s($meta), 'bms-comm2-thread-meta');
            if ((int)$thread['last_message_at'] > 0) {
                $body .= \html_writer::div(s(userdate((int)$thread['last_message_at'])), 'bms-comm2-thread-time');
            }
            $items .= \html_writer::link(self::url($company, ['tab' => $tab, 'thread' => $thread['uuid'], 'q' => $data['search']]),
                $body, ['class' => $classes]);
        }
        if ($items === '') { $items = task_ui_service::note(self::tr('communicationcenter_no_threads')); }
        $html .= \html_writer::div($items, 'bms-comm2-thread-list');
        return \html_writer::tag('aside', $html, ['class' => 'bms-comm2-panel bms-comm2-sidebar']);
    }

    private static function feed(string $company, array $data, string $convertmessage): string {
        if ($data['tab'] === 'meetings') {
            $body = \html_writer::tag('h2', s(self::tr('meeting_title'))) .
                task_ui_service::note(self::tr('communicationcenter_meetings_help')) .
                task_ui_service::link(new \moodle_url('/local/kbacompany/meetings.php', ['uuid' => $company]),
                    self::tr('meeting_open_center'), true);
            return \html_writer::tag('main', $body, ['class' => 'bms-comm2-panel bms-comm2-feed']);
        }
        if ($data['tab'] === 'notifications') {
            return \html_writer::tag('main', self::notification_feed($company, $data),
                ['class' => 'bms-comm2-panel bms-comm2-feed']);
        }

        $thread = $data['selected'];
        if (!$thread) {
            $body = \html_writer::tag('h2', s(self::tr('communicationcenter_selectthread'))) .
                task_ui_service::note(self::tr('communicationcenter_selectthread_help'));
            if ($data['tab'] === 'inbox' && !empty($data['notifications'])) {
                $body .= \html_writer::tag('h3', s(self::tr('communication_notifications')));
                $body .= self::notification_cards($company, $data['notifications'], 5);
            }
            return \html_writer::tag('main', $body, ['class' => 'bms-comm2-panel bms-comm2-feed']);
        }

        $body = \html_writer::tag('h2', s($thread['title']), ['class' => 'bms-comm2-feed-title']);
        if (!empty($thread['context'])) {
            $body .= \html_writer::div(s($thread['context']['label'] . ' · ' . $thread['context']['title']),
                'bms-comm2-feed-context');
        }
        $messagehtml = '';
        foreach ($data['messages'] as $message) {
            $messagehtml .= self::message_card($company, $thread, $message, $data['employees'], $data['tab']);
        }
        if ($messagehtml === '') { $messagehtml = task_ui_service::note(self::tr('communicationcenter_no_messages')); }
        $body .= \html_writer::div($messagehtml, 'bms-comm2-messages');

        $mentionoptions = [];
        foreach ($thread['participants'] as $participant) {
            if ((int)$participant['user_id'] !== (int)$GLOBALS['USER']->id) {
                $mentionoptions[(int)$participant['user_id']] = $participant['name'];
            }
        }
        $form = self::post_base($company, 'send', $thread['uuid']);
        $form .= \html_writer::label(s(self::tr('communication_message')), 'bms_comm2_body');
        $form .= \html_writer::tag('textarea', '', ['id' => 'bms_comm2_body', 'name' => 'body', 'rows' => 4,
            'class' => 'form-control', 'required' => 'required']);
        if ($mentionoptions) {
            $form .= \html_writer::label(s(self::tr('communication_mentions')), 'bms_comm2_mentions');
            $form .= \html_writer::select($mentionoptions, 'mentions[]', [], false,
                ['id' => 'bms_comm2_mentions', 'class' => 'form-control', 'multiple' => 'multiple']);
            $form .= \html_writer::tag('small', s(self::tr('communication_mentions_help')), ['class' => 'bms-tui-note']);
        }
        $form .= \html_writer::label(s(self::tr('communication_attachment')), 'bms_comm2_attachment');
        $form .= \html_writer::empty_tag('input', ['type' => 'file', 'name' => 'attachment', 'id' => 'bms_comm2_attachment',
            'class' => 'form-control-file']);
        $form .= self::button(self::tr('communication_send'));
        $body .= \html_writer::tag('form', $form, ['method' => 'post', 'enctype' => 'multipart/form-data',
            'class' => 'bms-comm2-compose']);
        return \html_writer::tag('main', $body, ['class' => 'bms-comm2-panel bms-comm2-feed']);
    }

    private static function message_card(string $company, array $thread, array $message, array $employees, string $tab): string {
        $content = $message['status'] === 'active' ? format_text($message['body'], FORMAT_PLAIN) :
            \html_writer::tag('em', s(self::tr('communication_messagewithdrawn')));
        $meta = s($message['author_name']) . ' · ' . s(userdate((int)$message['time_created']));
        $html = \html_writer::div($meta, 'bms-comm2-message-meta') . \html_writer::div($content, 'bms-comm2-message-body');
        if (!empty($message['mentions'])) {
            $names = [];
            foreach ($message['mentions'] as $userid) {
                if (isset($employees[(int)$userid])) { $names[] = '@' . $employees[(int)$userid]; }
            }
            if ($names) { $html .= \html_writer::div(s(implode(', ', $names)), 'bms-comm2-mentions'); }
        }
        if (!empty($message['attachments'])) {
            $files = '';
            foreach ($message['attachments'] as $attachment) {
                $label = $attachment['label'] !== '' ? $attachment['label'] : $attachment['value'];
                $files .= $attachment['url'] !== '' ?
                    \html_writer::link($attachment['url'], s($label), ['class' => 'btn btn-sm btn-outline-secondary']) :
                    \html_writer::tag('span', s($label), ['class' => 'badge badge-light']);
            }
            $html .= \html_writer::div($files, 'bms-comm2-files');
        }
        $actions = '';
        if ($message['task_uuid'] !== '') {
            $actions .= task_ui_service::link(task_ui_service::url($company, ['task' => $message['task_uuid']]),
                self::tr('communication_opentask'));
        } else if ($message['status'] === 'active') {
            $actions .= task_ui_service::link(self::url($company, ['tab' => $tab, 'thread' => $thread['uuid'],
                'convert' => $message['uuid']]), self::tr('communication_createtask'));
        }
        if ($message['status'] === 'active' && $thread['context_kind'] === 'meeting') {
            $form = self::post_base($company, 'createdecision', $thread['uuid']) .
                self::hidden(['messageuuid' => $message['uuid']]) .
                self::button(self::tr('communication_createdecision'), 'btn btn-sm btn-outline-primary');
            $actions .= \html_writer::tag('form', $form, ['method' => 'post', 'class' => 'd-inline-block']);
        }
        if ($actions !== '') { $html .= \html_writer::div($actions, 'bms-comm2-message-actions'); }
        return \html_writer::tag('article', $html, ['class' => 'bms-comm2-message', 'id' => 'message-' . $message['uuid']]);
    }

    private static function notification_feed(string $company, array $data): string {
        $html = \html_writer::tag('h2', s(self::tr('communication_notifications')));
        $html .= self::notification_cards($company, $data['notifications'], 30);
        return $html;
    }

    private static function notification_cards(string $company, array $notifications, int $limit): string {
        if (!$notifications) { return task_ui_service::note(self::tr('communication_nonotifications')); }
        $html = ''; $n = 0;
        foreach ($notifications as $notification) {
            if ($n++ >= $limit) { break; }
            $key = 'communication_notification_' . $notification['kind'];
            $kind = get_string_manager()->string_exists($key, 'local_kbacompany') ? self::tr($key) : self::tr('communication_notification');
            $actions = '';
            if ($notification['thread_uuid'] !== '') {
                $actions .= task_ui_service::link(self::url($company, ['tab' => 'all', 'thread' => $notification['thread_uuid']]),
                    self::tr('communication_open'));
            }
            if (!$notification['is_read']) {
                $form = self::post_base($company, 'readnotification') .
                    self::hidden(['notification' => $notification['uuid'], 'tab' => 'notifications']) .
                    self::button(self::tr('communication_markread'), 'btn btn-sm btn-outline-secondary');
                $actions .= \html_writer::tag('form', $form, ['method' => 'post', 'class' => 'd-inline-block']);
            }
            $html .= \html_writer::tag('article',
                \html_writer::tag('strong', s($kind)) .
                \html_writer::div(s($notification['title']), 'bms-comm2-notification-title') .
                \html_writer::div(s(userdate((int)$notification['time_created'])), 'bms-comm2-thread-time') .
                \html_writer::div($actions, 'bms-comm2-message-actions'),
                ['class' => 'bms-comm2-notification' . (!$notification['is_read'] ? ' is-unread' : '')]);
        }
        return $html;
    }

    private static function context_panel(string $company, array $data, ?array $objectrequest, int $actor,
            string $convertmessage): string {
        $thread = $data['selected'];
        $html = '';
        if ($thread && !empty($thread['context'])) {
            $context = $thread['context'];
            $html .= \html_writer::tag('h2', s(self::tr('communicationcenter_context_title')));
            $html .= \html_writer::tag('div', s($context['label']), ['class' => 'bms-comm2-context-kind']);
            $html .= \html_writer::tag('strong', s($context['title']), ['class' => 'bms-comm2-context-name']);
            if ($context['url'] !== '') {
                $html .= \html_writer::div(task_ui_service::link(new \moodle_url($context['url']),
                    self::tr('communication_opencontext')), 'bms-comm2-context-actions');
            }
        } else if ($objectrequest) {
            $html .= \html_writer::tag('h2', s(self::tr('communicationcenter_context_title')));
            $html .= \html_writer::tag('div', s($objectrequest['label']), ['class' => 'bms-comm2-context-kind']);
            $html .= \html_writer::tag('strong', s($objectrequest['title']), ['class' => 'bms-comm2-context-name']);
            $form = self::post_base($company, 'createobject') . self::hidden([
                'objectkind' => $objectrequest['kind'], 'objectuuid' => $objectrequest['uuid'],
                'objectrevision' => $objectrequest['revision'],
            ]) . self::button(self::tr('communicationcenter_startdiscussion'));
            $html .= \html_writer::tag('form', $form, ['method' => 'post', 'class' => 'bms-comm2-context-actions']);
        }

        if ($thread) {
            $html .= \html_writer::tag('h3', s(self::tr('communication_participants')));
            $people = '';
            $isowner = false;
            $participantids = [];
            foreach ($thread['participants'] as $participant) {
                $participantids[] = (int)$participant['user_id'];
                if ((int)$participant['user_id'] === $actor && $participant['role'] === 'owner') { $isowner = true; }
                $people .= \html_writer::div(s($participant['name']), 'bms-comm2-person');
            }
            $html .= \html_writer::div($people, 'bms-comm2-people');
            if ($isowner && $thread['thread_type'] !== 'direct') {
                $available = array_diff_key(self::person_options($data['employees'], $actor), array_flip($participantids));
                if ($available) {
                    $form = self::post_base($company, 'addparticipant', $thread['uuid']);
                    $form .= \html_writer::select($available, 'participantid', '', false, ['class' => 'form-control']);
                    $form .= self::button(self::tr('communication_addparticipant'), 'btn btn-outline-secondary');
                    $html .= \html_writer::tag('form', $form, ['method' => 'post', 'class' => 'bms-comm2-add-person']);
                }
            }
        }

        if ($thread && $convertmessage !== '') {
            $message = null;
            foreach ($data['messages'] as $candidate) {
                if ($candidate['uuid'] === $convertmessage) { $message = $candidate; break; }
            }
            if ($message && $message['status'] === 'active' && $message['task_uuid'] === '') {
                $html .= self::task_form($company, $thread, $message, $data['employees'], $actor);
            }
        }

        $html .= self::new_conversation_form($company, $data['employees'], $actor);
        return \html_writer::tag('aside', $html, ['class' => 'bms-comm2-panel bms-comm2-context']);
    }

    private static function new_conversation_form(string $company, array $employees, int $actor): string {
        $people = self::person_options($employees, $actor);
        if (!$people) { return ''; }
        $form = self::post_base($company, 'createthread');
        $form .= \html_writer::label(s(self::tr('communication_type')), 'bms_comm2_type');
        $form .= \html_writer::select([
            'direct' => self::tr('communication_type_direct'), 'group' => self::tr('communication_type_group'),
        ], 'threadtype', 'direct', false, ['id' => 'bms_comm2_type', 'class' => 'form-control']);
        $form .= \html_writer::label(s(self::tr('communication_thread')), 'bms_comm2_title');
        $form .= \html_writer::empty_tag('input', ['type' => 'text', 'id' => 'bms_comm2_title', 'name' => 'title',
            'class' => 'form-control', 'maxlength' => 255, 'placeholder' => self::tr('communicationcenter_group_title_hint')]);
        $form .= \html_writer::label(s(self::tr('communication_participants')), 'bms_comm2_participants');
        $form .= \html_writer::select($people, 'participants[]', [], false,
            ['id' => 'bms_comm2_participants', 'class' => 'form-control', 'multiple' => 'multiple', 'required' => 'required']);
        $form .= \html_writer::tag('small', s(self::tr('communicationcenter_participants_help')), ['class' => 'bms-tui-note']);
        $form .= self::button(self::tr('communication_create'));
        return \html_writer::tag('details',
            \html_writer::tag('summary', s(self::tr('communicationcenter_newconversation')), ['class' => 'btn btn-outline-primary']) .
            \html_writer::tag('form', $form, ['method' => 'post', 'class' => 'bms-comm2-new-form']),
            ['class' => 'bms-comm2-new']);
    }

    private static function task_form(string $company, array $thread, array $message, array $employees, int $actor): string {
        $streams = [];
        foreach (task_service::STREAMS as $stream) { $streams[$stream] = self::tr('taskcenter_stream_' . $stream); }
        $priorities = [];
        foreach (task_service::PRIORITIES as $priority) { $priorities[$priority] = self::tr('taskcenter_priority_' . $priority); }
        $reviewers = [0 => self::tr('taskcenter_noreviewer')] + $employees;
        $title = \core_text::substr(trim(preg_replace('/\s+/u', ' ', $message['body'])), 0, 120);
        $form = self::post_base($company, 'createtask', $thread['uuid']);
        $form .= self::hidden(['messageuuid' => $message['uuid'], 'startdate' => '', 'planminutes' => 0]);
        $form .= \html_writer::label(s(self::tr('taskcenter_task')), 'bms_comm2_task_title');
        $form .= \html_writer::empty_tag('input', ['type' => 'text', 'name' => 'title', 'id' => 'bms_comm2_task_title',
            'value' => $title, 'class' => 'form-control', 'required' => 'required']);
        $form .= \html_writer::label(s(self::tr('taskcenter_product')), 'bms_comm2_task_product');
        $form .= \html_writer::tag('textarea', '', ['name' => 'product', 'id' => 'bms_comm2_task_product', 'rows' => 2,
            'class' => 'form-control', 'required' => 'required']);
        $form .= \html_writer::label(s(self::tr('taskcenter_description')), 'bms_comm2_task_description');
        $form .= \html_writer::tag('textarea', s($message['body']), ['name' => 'description', 'id' => 'bms_comm2_task_description',
            'rows' => 3, 'class' => 'form-control']);
        $form .= \html_writer::label(s(self::tr('taskcenter_assignee')), 'bms_comm2_task_assignee');
        $form .= \html_writer::select($employees, 'assigneeid', $actor, false, ['id' => 'bms_comm2_task_assignee', 'class' => 'form-control']);
        $form .= \html_writer::label(s(self::tr('taskcenter_reviewer')), 'bms_comm2_task_reviewer');
        $form .= \html_writer::select($reviewers, 'reviewerid', 0, false, ['id' => 'bms_comm2_task_reviewer', 'class' => 'form-control']);
        $form .= \html_writer::label(s(self::tr('taskcenter_stream')), 'bms_comm2_task_stream');
        $form .= \html_writer::select($streams, 'stream', 'operate', false, ['id' => 'bms_comm2_task_stream', 'class' => 'form-control']);
        $form .= \html_writer::label(s(self::tr('taskcenter_priority')), 'bms_comm2_task_priority');
        $form .= \html_writer::select($priorities, 'priority', 'normal', false, ['id' => 'bms_comm2_task_priority', 'class' => 'form-control']);
        $form .= \html_writer::label(s(self::tr('taskcenter_duedate')), 'bms_comm2_task_due');
        $form .= \html_writer::empty_tag('input', ['type' => 'date', 'name' => 'duedate', 'id' => 'bms_comm2_task_due', 'class' => 'form-control']);
        $form .= self::button(self::tr('communication_createtask'), 'btn btn-success');
        return \html_writer::tag('section', \html_writer::tag('h3', s(self::tr('communication_taskfrommessage'))) .
            \html_writer::tag('form', $form, ['method' => 'post', 'class' => 'bms-comm2-task-form']),
            ['class' => 'bms-comm2-task-create']);
    }

    /** Kept for compatibility with older callers; uses the new workspace when possible. */
    public static function screen(string $company, array $data, string $search): string {
        return task_ui_service::note(self::tr('communicationcenter_intro'));
    }
}
