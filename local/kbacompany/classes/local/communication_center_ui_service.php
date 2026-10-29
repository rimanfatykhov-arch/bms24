<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Communication Center 2.0 presentation over the canonical communication service. */
class communication_center_ui_service {
    public static function url(string $company, array $params = []): \moodle_url {
        return new \moodle_url('/local/kbacompany/communication_center.php', ['uuid' => $company] + $params);
    }

    private static function tr(string $key, $a = null): string {
        // Moodle language cache may lag the installed plugin upgrade; avoid raw [[keys]].
        if ($key === 'communicationcenter_search_messages' && !get_string_manager()->string_exists($key, 'local_kbacompany')) {
            return current_language() === 'ru' ? 'Поиск по сообщениям' : 'Search messages';
        }
        return get_string($key, 'local_kbacompany', $a);
    }

    /** Non-identifying fallback avatar for dialog names; all text is escaped on output. */
    private static function initials(string $name): string {
        $parts = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY);
        if (!$parts) { return '•'; }
        $first = \core_text::substr($parts[0], 0, 1);
        $last = count($parts) > 1 ? \core_text::substr($parts[count($parts) - 1], 0, 1) : '';
        return \core_text::strtoupper($first . $last);
    }

    private static function avatar(string $name, string $kind = ''): string {
        return \html_writer::tag('span', s(self::initials($name)),
            ['class'=>'bms-comm3-avatar' . ($kind === 'object' ? ' is-object' : ''), 'aria-hidden'=>'true']);
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
        $counts = $data['counts'];
        // Primary navigation follows the approved concept: the left pane owns the
        // conversation navigation and the centre pane stays focused on the chat.
        // Advanced modes remain available as compact chips instead of a separate rail.
        $taborder = ['all', 'unread', 'mentions', 'notifications', 'meetings'];
        $tabs = [];
        foreach ($taborder as $key) {
            $badge = '';
            if ($key === 'unread' && !empty($counts['unread_threads'])) {
                $badge = \html_writer::tag('span', (string)(int)$counts['unread_threads'], ['class' => 'bms-comm3-tab-badge']);
            } else if ($key === 'mentions' && !empty($counts['mentions'])) {
                $badge = \html_writer::tag('span', (string)(int)$counts['mentions'], ['class' => 'bms-comm3-tab-badge']);
            } else if ($key === 'notifications' && !empty($counts['unread_notifications'])) {
                $badge = \html_writer::tag('span', (string)(int)$counts['unread_notifications'], ['class' => 'bms-comm3-tab-badge']);
            }
            $label = \html_writer::tag('span', s(self::tr('communicationcenter_tab_' . $key)), ['class' => 'bms-comm3-tab-label']) . $badge;
            $tabs[] = \html_writer::link(self::url($company, ['tab' => $key]), $label,
                ['class' => 'bms-comm2-filter' . ($key === $tab ? ' is-active' : ''),
                 'aria-current' => $key === $tab ? 'page' : null]);
        }
        $navigation = \html_writer::tag('nav', implode('', $tabs),
            ['class' => 'bms-comm2-filters',
            'aria-label' => self::tr('communicationcenter_title')]);
        $navigation .= \html_writer::div(
            \html_writer::tag('span', s(self::tr('communication_unreadthreads') . ': ' . (int)$counts['unread_threads'])) .
            \html_writer::tag('span', s(self::tr('communication_mentions') . ': ' . (int)$counts['mentions'])) .
            \html_writer::tag('span', s(self::tr('communication_notifications') . ': ' . (int)$counts['unread_notifications'])),
            'bms-comm2-inline-stats');
        $left = self::thread_sidebar($company, $data, $navigation);
        $centre = self::feed($company, $data, $convertmessage);
        $right = self::context_panel($company, $data, $objectrequest, (int)$USER->id, $convertmessage);
        return \html_writer::div($left . $centre . $right, 'bms-comm2-layout',
            ['data-has-thread' => $selected ? '1' : '0']);
    }

    /** Compact, accessible object-mode switcher; navigation only, never a data source. */
    private static function object_rail(string $company, string $tab, array $counts): string {
        $icons = [
            'inbox' => '✉', 'messages' => '▤', 'discussions' => '☷',
            'mentions' => '@', 'notifications' => '♧', 'meetings' => '▦',
        ];
        $links = '';
        foreach ($icons as $mode => $icon) {
            $label = self::tr('communicationcenter_tab_' . $mode);
            $badge = '';
            if ($mode === 'mentions' && !empty($counts['mentions'])) {
                $badge = (string)(int)$counts['mentions'];
            } else if ($mode === 'notifications' && !empty($counts['unread_notifications'])) {
                $badge = (string)(int)$counts['unread_notifications'];
            }
            $content = \html_writer::tag('span', s($icon), ['class' => 'bms-comm2-rail-icon', 'aria-hidden' => 'true']);
            if ($badge !== '') {
                $content .= \html_writer::tag('span', s($badge), ['class' => 'bms-comm2-rail-badge',
                    'aria-label' => $label . ': ' . $badge]);
            }
            $links .= \html_writer::link(self::url($company, ['tab' => $mode]), $content,
                ['class' => 'bms-comm2-rail-link' . ($tab === $mode ? ' is-active' : ''),
                 'title' => $label, 'aria-label' => $label,
                 'aria-current' => $tab === $mode ? 'page' : null]);
        }
        return \html_writer::tag('nav', $links, ['class' => 'bms-comm2-rail',
            'aria-label' => self::tr('communicationcenter_title')]);
    }

    private static function thread_sidebar(string $company, array $data, string $navigation = ''): string {
        $tab = (string)$data['tab'];
        $search = (string)$data['search'];
        $form = self::hidden(['uuid' => $company, 'tab' => $tab]);
        $form .= \html_writer::label(s(self::tr('tui_search')), 'bms_comm2_search', false, ['class' => 'sr-only']);
        $form .= \html_writer::empty_tag('input', ['type' => 'search', 'id' => 'bms_comm2_search', 'name' => 'q',
            'value' => $search, 'class' => 'form-control', 'placeholder' => self::tr('tui_search')]);
        $form .= \html_writer::empty_tag('input', ['type' => 'submit', 'hidden' => 'hidden', 'tabindex' => '-1']);
        // One compact new-thread action in the title row; do not push the scrollable list down.
        // The details/form still posts to the canonical communication service with sesskey.
        $newthread = self::new_conversation_form($company, $data['employees'], (int)$GLOBALS['USER']->id);
        $unread = max(0, (int)($data['counts']['unread_threads'] ?? 0));
        $unreadindicator = \html_writer::tag('span', (string)$unread,
            ['class' => 'bms-comm4-unread-indicator',
             'aria-label' => self::tr('communication_unreadthreads'),
             'aria-live' => 'polite', 'hidden' => $unread ? null : 'hidden']);
        $sidebartitle = \html_writer::div(
            \html_writer::tag('span', '💬', ['class' => 'bms-comm3-sidebar-icon', 'aria-hidden' => 'true']) .
            \html_writer::tag('strong', s(self::tr('communicationcenter_conversations_title'))),
            'bms-comm3-sidebar-title');
        $html = \html_writer::div($sidebartitle . $unreadindicator . $newthread,
            'bms-comm2-sidebar-head');
        $html .= $navigation;
        $html .= \html_writer::tag('form', $form, ['method' => 'get', 'class' => 'bms-comm2-search']);

        if ($tab === 'meetings') {
            $html .= task_ui_service::note(self::tr('communicationcenter_meetings_help'));
            $html .= task_ui_service::link(new \moodle_url('/local/kbacompany/meetings.php', ['uuid' => $company]),
                self::tr('meeting_open_center'), true);
            return \html_writer::tag('aside', $html, ['class' => 'bms-comm2-panel bms-comm2-sidebar', 'aria-label' => self::tr('communicationcenter_conversations_title')]);
        }
        if ($tab === 'notifications') {
            $html .= task_ui_service::note(self::tr('communicationcenter_notifications_help'));
            return \html_writer::tag('aside', $html, ['class' => 'bms-comm2-panel bms-comm2-sidebar', 'aria-label' => self::tr('communicationcenter_conversations_title')]);
        }

        $items = '';
        foreach ($data['threads'] as $thread) {
            $classes = 'bms-comm2-thread';
            if (!empty($data['selected']) && $data['selected']['uuid'] === $thread['uuid']) { $classes .= ' is-active'; }
            if ((int)$thread['unread_count'] > 0) { $classes .= ' has-unread'; }
            $meta = '';
            if (!empty($thread['context'])) {
                $meta = $thread['context']['label'] . ' · ' . $thread['context']['title'];
            } else {
                $meta = self::tr($thread['thread_type'] === 'direct' ? 'communication_type_direct' : 'communication_type_group');
            }
            $badge = (int)$thread['unread_count'] > 0 ?
                \html_writer::tag('span', (string)(int)$thread['unread_count'],
                    ['class' => 'bms-comm3-unread', 'aria-label'=>self::tr('communication_unreadthreads')]) : '';
            $time = (int)$thread['last_message_at'] > 0 ?
                \html_writer::tag('time', s(userdate((int)$thread['last_message_at'], '%H:%M')),
                    ['class'=>'bms-comm3-thread-time']) : '';
            $title = \html_writer::tag('strong', s($thread['title']), ['class'=>'bms-comm3-thread-name']);
            $top = \html_writer::div($title . $time, 'bms-comm3-thread-top');
            $contextlabel = !empty($thread['context']) ? (string)$thread['context']['label'] : $meta;
            $details = \html_writer::div(s($contextlabel), 'bms-comm3-thread-kind');
            if (!empty($thread['preview'])) {
                $details .= \html_writer::div(s((string)$thread['preview']['body']), 'bms-comm3-thread-preview');
            }
            $info = \html_writer::div($top . $details, 'bms-comm3-thread-info');
            $trailing = $badge;
            if (!empty($thread['has_unread_mention'])) {
                $trailing .= \html_writer::tag('span', '@',
                    ['class'=>'bms-comm3-mention', 'aria-label'=>self::tr('communicationcenter_unread_mention_signal')]);
            }
            $body = self::avatar((string)$thread['title'], $thread['thread_type'] === 'object' ? 'object' : '') .
                $info . \html_writer::div($trailing, 'bms-comm3-thread-flags');
            $items .= \html_writer::link(self::url($company,
                ['tab'=>$tab, 'thread'=>$thread['uuid'], 'q'=>$data['search']]), $body,
                ['class'=>$classes . ' bms-comm3-thread',
                 'data-thread-uuid'=>$thread['uuid'],
                 'aria-current'=>strpos($classes, 'is-active') !== false ? 'true' : null]);
        }
        if ($items === '') { $items = task_ui_service::note(self::tr('communicationcenter_no_threads')); }
        $html .= \html_writer::div($items, 'bms-comm2-thread-list');
        return \html_writer::tag('aside', $html, ['class' => 'bms-comm2-panel bms-comm2-sidebar', 'aria-label' => self::tr('communicationcenter_conversations_title')]);
    }

    private static function feed(string $company, array $data, string $convertmessage): string {
        if ($data['tab'] === 'meetings') {
            $body = \html_writer::tag('h2', s(self::tr('meeting_title'))) .
                task_ui_service::note(self::tr('communicationcenter_meetings_help')) .
                task_ui_service::link(new \moodle_url('/local/kbacompany/meetings.php', ['uuid' => $company]),
                    self::tr('meeting_open_center'), true);
            return \html_writer::tag('main', $body, ['class' => 'bms-comm2-panel bms-comm2-feed', 'aria-label' => self::tr('communicationcenter_selectthread')]);
        }
        if ($data['tab'] === 'notifications') {
            return \html_writer::tag('main', self::notification_feed($company, $data),
                ['class' => 'bms-comm2-panel bms-comm2-feed', 'aria-label' => self::tr('communicationcenter_selectthread')]);
        }

        $thread = $data['selected'];
        if (!$thread) {
            // An intentional messenger welcome state, rather than a plain text placeholder.
            // Static SVG is presentation-only and does not expose thread or employee data.
            $bubbleicon = '<svg viewBox="0 0 96 96" width="96" height="96" fill="none" aria-hidden="true" focusable="false">' .
                '<path d="M18 23c0-5 4-9 9-9h36c5 0 9 4 9 9v25c0 5-4 9-9 9H42L27 70V57c-5 0-9-4-9-9V23Z" fill="currentColor" opacity=".95"/>' .
                '<path d="M58 58h13c5 0 9-4 9-9V34c5 1 8 5 8 10v24c0 5-4 9-9 9v10L65 77H57c-5 0-9-4-9-9v-3" fill="currentColor" opacity=".8"/>' .
                '</svg>';
            $body = \html_writer::div(
                \html_writer::div($bubbleicon, 'bms-comm2-welcome-icon') .
                \html_writer::tag('h2', s(self::tr('communicationcenter_welcome_title')), ['class' => 'bms-comm2-welcome-title']) .
                \html_writer::tag('p', s(self::tr('communicationcenter_welcome_help')), ['class' => 'bms-comm2-welcome-help']),
                'bms-comm2-welcome-content');
            if ($data['tab'] === 'inbox' && !empty($data['notifications'])) {
                $body .= \html_writer::tag('h3', s(self::tr('communication_notifications')));
                $body .= self::notification_cards($company, $data['notifications'], 5);
            }
            return \html_writer::tag('main', $body, ['class' => 'bms-comm2-panel bms-comm2-feed bms-comm2-welcome', 'aria-label' => self::tr('communicationcenter_selectthread')]);
        }

        $participantcount = count($thread['participants']);
        $heading = \html_writer::div(
            \html_writer::tag('h2', s($thread['title']), ['class' => 'bms-comm2-feed-title']) .
            \html_writer::tag('span', s(self::tr('communication_participants') . ': ' . $participantcount),
                ['class' => 'bms-comm2-feed-people']), 'bms-comm3-chat-heading');
        $controls = \html_writer::tag('button', '⌕', ['type'=>'button',
            'class'=>'bms-comm3-iconbtn', 'data-bms-comm-search-toggle'=>'1',
            'aria-label'=>self::tr('communicationcenter_search_messages'),
            'title'=>self::tr('communicationcenter_search_messages'), 'aria-controls'=>'bms-comm3-search-panel',
            'aria-expanded'=>'false']);
        $controls .= \html_writer::tag('button', '☷', ['type'=>'button',
            'class'=>'bms-comm3-iconbtn', 'data-bms-comm-context-toggle'=>'1',
            'aria-label'=>self::tr('communicationcenter_context_title'),
            'title'=>self::tr('communicationcenter_context_title'), 'aria-expanded'=>'false']);
        $back = \html_writer::link(self::url($company, ['tab'=>$data['tab']]), '‹',
            ['class'=>'bms-comm3-mobile-back', 'aria-label'=>self::tr('communicationcenter_conversations_title')]);
        $body = \html_writer::div($back . self::avatar((string)$thread['title'],
            $thread['thread_type'] === 'object' ? 'object' : '') . $heading .
            \html_writer::div($controls, 'bms-comm3-chat-controls'), 'bms-comm2-feed-head');
        if (!empty($thread['context'])) {
            $body .= \html_writer::div(s($thread['context']['label'] . ' · ' . $thread['context']['title']),
                'bms-comm2-feed-context');
        }
        // Search operates only on the canonical already-authorized selected thread projection.
        // There is no separate messages query, write operation, or change to read state.
        $messagequery = trim(\core_text::substr((string)($data['message_search'] ?? ''), 0, 200));
        $searchform = self::hidden(['uuid' => $company, 'tab' => $data['tab'], 'thread' => $thread['uuid']]);
        $searchform .= \html_writer::label(s(self::tr('communicationcenter_search_messages')),
            'bms_comm2_messagesearch', false, ['class'=>'sr-only']);
        $searchform .= \html_writer::empty_tag('input', ['type'=>'search', 'id'=>'bms_comm2_messagesearch',
            'name'=>'mq', 'value'=>$messagequery, 'class'=>'form-control',
            'placeholder'=>self::tr('communicationcenter_search_messages')]);
        $searchform .= \html_writer::empty_tag('input', ['type' => 'submit', 'hidden' => 'hidden', 'tabindex' => '-1']);
        if ($messagequery !== '') {
            $searchform .= \html_writer::link(self::url($company, ['tab'=>$data['tab'], 'thread'=>$thread['uuid']]),
                s(self::tr('communicationcenter_clear_search')), ['class'=>'btn btn-outline-secondary']);
        }
        $body .= \html_writer::tag('form', $searchform, ['method'=>'get', 'id'=>'bms-comm3-search-panel',
            'class'=>'bms-comm2-search bms-comm2-message-search', 'data-has-query'=>$messagequery !== '' ? '1' : '0']);
        // Canonical DB-bounded pagination. The core enforces company, participant and object ACL.
        $visiblemessages = $data['messages'];
        $history = $data['history'] ?? ['total' => count($visiblemessages), 'page' => 0, 'pages' => 1];
        $matched = (int)$history['total'];
        $pages = max(1, (int)$history['pages']);
        $page = (int)$history['page'];
        $messagehtml = '';
        $previousday = null;
        foreach ($visiblemessages as $message) {
            $day = userdate((int)$message['time_created'], '%Y-%m-%d');
            if ($day !== $previousday) {
                $today = userdate(time(), '%Y-%m-%d');
                $yesterday = userdate(time() - DAYSECS, '%Y-%m-%d');
                $label = $day === $today ? self::tr('bmscomm3_today') :
                    ($day === $yesterday ? self::tr('bmscomm3_yesterday') :
                    userdate((int)$message['time_created'], '%d.%m.%Y'));
                $messagehtml .= \html_writer::div(s($label), 'bms-comm3-day-separator');
                $previousday = $day;
            }
            $messagehtml .= self::message_card($company, $thread, $message, $data['employees'], $data['tab']);
        }
        if ($messagequery !== '') {
            $body .= \html_writer::div(s(get_string('communicationcenter_search_count','local_kbacompany', $matched)),
                'bms-comm2-search-count');
        }
        if ($messagehtml === '') {
            $messagehtml = task_ui_service::note(self::tr($messagequery !== '' ?
                'communicationcenter_search_empty' : 'communicationcenter_no_messages'));
        }
        $body .= \html_writer::div($messagehtml, 'bms-comm2-messages', ['id' => 'bms-comm2-history']);
        if ($pages > 1) {
            $base = ['tab'=>$data['tab'], 'thread'=>$thread['uuid']];
            if ($messagequery !== '') { $base['mq'] = $messagequery; }
            $pager = \html_writer::tag('span', s(get_string('communicationcenter_history_page',
                'local_kbacompany', (object)['current'=>$page + 1, 'total'=>$pages])), ['class'=>'bms-comm2-page-number']);
            if ($page + 1 < $pages) {
                $pager = \html_writer::link(self::url($company, $base + ['mpage'=>$page+1]),
                    s(self::tr('communicationcenter_older_messages')), ['class'=>'btn btn-outline-secondary btn-sm']) . $pager;
            }
            if ($page > 0) {
                $pager .= \html_writer::link(self::url($company, $base + ['mpage'=>$page-1]),
                    s(self::tr('communicationcenter_newer_messages')), ['class'=>'btn btn-outline-secondary btn-sm']);
            }
            $body .= \html_writer::tag('nav', $pager,
                ['class'=>'bms-comm2-message-pager',
                 'aria-label'=>self::tr('communicationcenter_history_navigation')]);
        }

        $mentionoptions = [];
        foreach ($thread['participants'] as $participant) {
            if ((int)$participant['user_id'] !== (int)$GLOBALS['USER']->id) {
                $mentionoptions[(int)$participant['user_id']] = $participant['name'];
            }
        }
        $form = self::post_base($company, 'send', $thread['uuid']);
        $form .= \html_writer::label(s(self::tr('communication_message')), 'bms_comm2_body', false, ['class' => 'sr-only']);
        $form .= \html_writer::tag('textarea', '', ['id' => 'bms_comm2_body', 'name' => 'body', 'rows' => 3,
            'class' => 'form-control', 'placeholder' => self::tr('communicationcenter_message_placeholder'), 'required' => 'required']);
        $tools = '';
        $tools .= \html_writer::label('📎', 'bms_comm2_attachment', false,
            ['class' => 'bms-comm3-tool', 'title' => self::tr('communication_attachment'),
             'aria-label' => self::tr('communication_attachment')]);
        $tools .= \html_writer::empty_tag('input', ['type' => 'file', 'name' => 'attachment', 'id' => 'bms_comm2_attachment',
            'class' => 'bms-comm3-file-input']);
        if ($mentionoptions) {
            $mentionbody = \html_writer::label(s(self::tr('communication_mentions')), 'bms_comm2_mentions');
            $mentionbody .= \html_writer::select($mentionoptions, 'mentions[]', [], false,
                ['id' => 'bms_comm2_mentions', 'class' => 'form-control', 'multiple' => 'multiple']);
            $mentionbody .= \html_writer::tag('small', s(self::tr('communication_mentions_help')), ['class' => 'bms-tui-note']);
            $tools .= \html_writer::tag('details',
                \html_writer::tag('summary', '@', ['title' => self::tr('communication_mentions'),
                    'aria-label' => self::tr('communication_mentions')]) .
                \html_writer::div($mentionbody, 'bms-comm3-mention-picker-body'),
                ['class' => 'bms-comm3-mention-picker']);
        }
        $send = \html_writer::tag('button',
            \html_writer::tag('span', '➜', ['aria-hidden' => 'true']) .
            \html_writer::tag('span', s(self::tr('communication_send')), ['class' => 'sr-only']),
            ['type' => 'submit', 'class' => 'btn btn-primary bms-comm3-send']);
        $form .= \html_writer::div(
            \html_writer::div($tools, 'bms-comm3-compose-tools') .
            \html_writer::div($send, 'bms-comm3-compose-actions'),
            'bms-comm3-compose-bar');
        $body .= \html_writer::tag('form', $form, ['method' => 'post', 'enctype' => 'multipart/form-data',
            'class' => 'bms-comm2-compose', 'data-bms-comm-compose'=>'1']);
        return \html_writer::tag('main', $body, ['class' => 'bms-comm2-panel bms-comm2-feed', 'aria-label' => self::tr('communicationcenter_selectthread')]);
    }

    private static function message_card(string $company, array $thread, array $message, array $employees, string $tab): string {
        $content = $message['status'] === 'active' ? format_text($message['body'], FORMAT_PLAIN) :
            \html_writer::tag('em', s(self::tr('communication_messagewithdrawn')));
        $meta = s($message['author_name']) . ' · ' . s(userdate((int)$message['time_created']));
        if (!empty($message['edited']) && $message['status'] === 'active') {
            $meta .= ' · ' . s(self::tr('communication_edited'));
        }
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
        $own = (int)$message['author_id'] === (int)$GLOBALS['USER']->id;
        // Authors may correct or withdraw their own active messages. Linked tasks and
        // decisions are additionally protected server-side to preserve audit trails.
        if ($own && $message['status'] === 'active' && $message['task_uuid'] === '') {
            $basehash = hash('sha256', $message['body'] . "\n" . $message['status']);
            $editform = self::post_base($company, 'editmessage', $thread['uuid']) .
                self::hidden(['messageuuid' => $message['uuid'], 'expectedhash' => $basehash, 'tab' => $tab]);
            $editform .= \html_writer::label(s(self::tr('communication_message')),
                'editmsg_' . $message['uuid'], false, ['class' => 'sr-only']);
            $editform .= \html_writer::tag('textarea', s($message['body']), [
                'id' => 'editmsg_' . $message['uuid'], 'name' => 'body', 'rows' => 3,
                'required' => 'required', 'maxlength' => 20000, 'class' => 'form-control']);
            $editform .= self::button(self::tr('communication_saveedit'), 'btn btn-primary btn-sm');
            $edit = \html_writer::tag('details',
                \html_writer::tag('summary', s(self::tr('communication_editmessage'))) .
                \html_writer::tag('form', $editform, ['method' => 'post']),
                ['class' => 'bms-comm5-edit']);
            $deleteform = self::post_base($company, 'withdrawmessage', $thread['uuid']) .
                self::hidden(['messageuuid' => $message['uuid'], 'expectedhash' => $basehash, 'tab' => $tab]);
            $deleteform .= \html_writer::tag('p', s(self::tr('communication_deletenotice')));
            $checkid = 'deleteconfirm_' . $message['uuid'];
            $deleteform .= \html_writer::empty_tag('input', [
                'type' => 'checkbox', 'id' => $checkid, 'name' => 'confirmwithdraw',
                'value' => '1', 'required' => 'required']);
            $deleteform .= \html_writer::label(s(self::tr('communication_confirmdelete')), $checkid);
            $deleteform .= self::button(self::tr('communication_withdrawmessage'), 'btn btn-outline-danger btn-sm');
            $delete = \html_writer::tag('details',
                \html_writer::tag('summary', s(self::tr('communication_withdrawmessage'))) .
                \html_writer::tag('form', $deleteform, ['method' => 'post']),
                ['class' => 'bms-comm5-delete']);
            $html .= \html_writer::div($edit . $delete, 'bms-comm5-actions');
        }
        return \html_writer::tag('article', $html,
            ['class'=>'bms-comm2-message ' . ($own ? 'bms-comm3-outgoing' : 'bms-comm3-incoming'),
             'id'=>'message-' . $message['uuid']]);
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

        $summary = \html_writer::tag('span', '☰', ['class' => 'bms-comm3-context-icon', 'aria-hidden' => 'true']) .
            \html_writer::tag('span', s(self::tr('communicationcenter_context_title')), ['class' => 'sr-only']);
        return \html_writer::tag('aside', \html_writer::tag('details',
            \html_writer::tag('summary', $summary, ['title' => self::tr('communicationcenter_context_title'),
                'aria-label' => self::tr('communicationcenter_context_title')]) .
            \html_writer::tag('button', '×', ['type'=>'button',
                'class'=>'bms-comm3-context-close', 'data-bms-comm-context-close'=>'1',
                'aria-label'=>self::tr('bmscomm3_closecontext')]) .
            \html_writer::div($html, 'bms-comm2-context-body'),
            ['class' => 'bms-comm2-context-toggle', 'id'=>'bms-comm3-context-details']),
            ['class' => 'bms-comm2-panel bms-comm2-context']);
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
        $label = self::tr('communicationcenter_newconversation');
        $trigger = \html_writer::tag('summary',
            \html_writer::tag('span', '+', ['aria-hidden' => 'true']) .
            \html_writer::tag('span', s($label), ['class' => 'sr-only']),
            ['class' => 'bms-comm2-new-trigger', 'title' => $label, 'aria-label' => $label]);
        return \html_writer::tag('details',
            $trigger . \html_writer::tag('form', $form,
                ['method' => 'post', 'class' => 'bms-comm2-new-form']),
            ['class' => 'bms-comm2-new', 'id' => 'bms-comm2-start-conversation']);
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
