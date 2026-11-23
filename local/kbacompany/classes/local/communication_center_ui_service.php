<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Communication Center presentation (#145): builds a render model only and renders it with the
 * templates/bms24_comm_*.mustache templates. No SQL, access decisions or writes here; all data comes from
 * communication_center_service::workspace_context(), which applies company, participation and object ACL.
 * Legacy selectors and JS contracts (bms-comm2-*, bms-comm-ux-*, data-bms-comm-*) stay on the same elements.
 */
class communication_center_ui_service {
    public static function url(string $company, array $params = []): \moodle_url {
        return new \moodle_url('/local/kbacompany/communication_center.php', ['uuid' => $company] + $params);
    }

    private static function tr(string $key, $a = null): string {
        return get_string($key, 'local_kbacompany', $a);
    }

    /** Non-identifying fallback avatar text; escaped by Mustache. */
    public static function initials(string $name): string {
        $parts = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY);
        if (!$parts) { return '•'; }
        $first = \core_text::substr($parts[0], 0, 1);
        $last = count($parts) > 1 ? \core_text::substr($parts[count($parts) - 1], 0, 1) : '';
        return \core_text::strtoupper($first . $last);
    }

    /** @return array[] name/value pairs for hidden inputs. */
    private static function hidden(array $values): array {
        $out = [];
        foreach ($values as $name => $value) { $out[] = ['name' => (string)$name, 'value' => (string)$value]; }
        return $out;
    }

    private static function post_base(string $company, string $action, string $thread = ''): array {
        $values = ['uuid' => $company, 'action' => $action, 'sesskey' => sesskey()];
        if ($thread !== '') { $values['thread'] = $thread; }
        return self::hidden($values);
    }

    private static function options(array $values, $selected = null): array {
        $out = [];
        foreach ($values as $value => $label) {
            $out[] = ['value' => (string)$value, 'label' => (string)$label,
                'selected' => $selected !== null && (string)$selected === (string)$value];
        }
        return $out;
    }

    /** List time: today = time, yesterday = word, this week = weekday, older = date. */
    private static function short_time(int $time): string {
        if ($time <= 0) { return ''; }
        $day = business_date_service::day($time);
        if ($day === business_date_service::day(time())) { return userdate($time, '%H:%M'); }
        if ($day === business_date_service::yesterday()) { return self::tr('bmscomm3_yesterday'); }
        if (time() - $time < 6 * DAYSECS) { return userdate($time, '%a'); }
        return userdate($time, '%d.%m.%Y');
    }

    private static function day_label(int $time): string {
        $day = business_date_service::day($time);
        if ($day === business_date_service::day(time())) { return self::tr('bmscomm3_today'); }
        if ($day === business_date_service::yesterday()) { return self::tr('bmscomm3_yesterday'); }
        return userdate($time, '%A, %d.%m.%Y');
    }

    private static function kind_variant(string $kind): string {
        return ['task' => 'sky', 'battleplan' => 'mint', 'meeting' => 'sand'][$kind] ?? 'sky';
    }

    private static function date_text(string $ymd): string {
        $time = strtotime($ymd . ' 12:00:00 UTC');
        return $ymd !== '' && $time ? gmdate('d.m.Y', $time) : '';
    }

    /** Main Communication Center workspace. Public signature kept for callers and tests. */
    public static function workspace(string $company, array $data, ?array $objectrequest = null,
            string $convertmessage = ''): string {
        global $OUTPUT;
        $model = self::model($company, $data, $objectrequest, $convertmessage);
        // Reuse the UI Kit card: the header carries the section tabs and the primary action.
        $card = $OUTPUT->render_from_template('local_kbacompany/bms24_card', [
            'header' => true, 'title' => $model['title'],
            'actionshtml' => $OUTPUT->render_from_template('local_kbacompany/bms24_comm_header', $model),
            'contenthtml' => \html_writer::div($OUTPUT->render_from_template('local_kbacompany/bms24_comm_body', $model),
                'bms24-comm-cardbody'),
        ]);
        return \html_writer::div($card, 'bms24-comm', ['id' => 'bms24-comm-root', 'data-section' => $model['section']]);
    }

    /** Mustache looks a missing or null key up in the parent context; absent optional values are false instead. */
    private static function falsify(array $model): array {
        array_walk_recursive($model, static function(&$value): void {
            if ($value === null) { $value = false; }
        });
        return $model;
    }

    /** Render model of the whole center (exposed for PHPUnit; contains no HTML). */
    public static function model(string $company, array $data, ?array $objectrequest = null,
            string $convertmessage = ''): array {
        global $USER;
        $actor = (int)$USER->id;
        $tab = (string)$data['tab'];
        $section = in_array($tab, ['notifications', 'dayfeed'], true) ? $tab :
            ($tab === 'discussions' ? 'discussions' : 'messages');
        $sectionkeys = ['messages', 'discussions', 'notifications'];
        if (!empty($data['dayfeed_enabled'])) {
            $sectionkeys[] = 'dayfeed';
        }
        $sections = $data['sections'] ?? ['messages' => ['total' => 0, 'unread' => 0],
            'discussions' => ['total' => 0, 'unread' => 0]];
        $tabs = [];
        foreach ($sectionkeys as $key) {
            $label = self::tr($key === 'messages' ? 'communicationux_conversations' :
                ($key === 'dayfeed' ? 'dailyreports_feed_tab' : 'communicationcenter_tab_' . $key));
            $badge = null;
            if ($key === 'dayfeed') {
                $badge = null;
            } else if ($key === 'notifications') {
                $n = (int)($data['counts']['unread_notifications'] ?? 0);
                if ($n > 0) {
                    $badge = ['value' => $n, 'variant' => 'blue', 'title' => self::tr('communicationux_notification_count')];
                }
            } else {
                $unread = (int)($sections[$key]['unread'] ?? 0);
                $total = (int)($sections[$key]['total'] ?? 0);
                if ($unread > 0) {
                    $badge = ['value' => $unread, 'variant' => 'unread', 'title' => self::tr('communicationux2_badge_unread', $unread)];
                } else if ($total > 0) {
                    $badge = ['value' => $total, 'variant' => 'soft', 'title' => self::tr('communicationux2_badge_total', $total)];
                }
            }
            $tabs[] = ['key' => $key, 'label' => $label, 'url' => self::url($company, ['tab' => $key])->out(false),
                'active' => $key === $section, 'badge' => $badge];
        }
        $model = [
            'title' => self::tr('communicationcenter_title'),
            'sectionslabel' => self::tr('communicationcenter_title'),
            'tabs' => $tabs, 'section' => $section,
            'isnotifications' => $section === 'notifications',
            'isdayfeed' => $section === 'dayfeed',
            'newconversation' => self::new_conversation($company, $data['employees'], $actor),
        ];
        if ($section === 'dayfeed') {
            $model['dayfeed'] = self::day_feed($company, $data['dayfeed']);
            return self::falsify($model);
        }
        if ($section === 'notifications') {
            $model['notifications'] = self::notifications($company, $data);
            return self::falsify($model);
        }
        $selected = $data['selected'];
        $model['layout'] = [
            'section' => $section,
            'hasthread' => !empty($selected),
            'list' => self::thread_list($company, $data, $section, $sections),
            'thread' => $selected ? self::thread($company, $data, $actor) : null,
            'emptythread' => $selected ? null : ['title' => self::tr('communicationux2_select_title'),
                'text' => self::tr('communicationux2_select_text'), 'action' => null],
            'context' => ($selected || $objectrequest) ?
                self::context_panel($company, $data, $objectrequest, $actor, $convertmessage) : null,
        ];
        return self::falsify($model);
    }

    private static function thread_list(string $company, array $data, string $section, array $sections): array {
        global $USER;
        $search = (string)$data['search'];
        $filter = (string)($data['filter'] ?? 'all');
        $chips = [];
        foreach (['all' => 'communicationcenter_tab_all', 'unread' => 'communicationcenter_tab_unread',
                'mentions' => 'communicationux2_chip_mentions'] as $key => $string) {
            $chips[] = ['label' => self::tr($string), 'title' => self::tr($key === 'mentions' ?
                'communicationcenter_tab_mentions' : $string),
                'url' => self::url($company, ['tab' => $section, 'filter' => $key])->out(false), 'active' => $key === $filter];
        }
        $items = [];
        foreach ($data['threads'] as $thread) {
            $isobject = $thread['thread_type'] === 'object';
            $kind = null;
            if ($isobject && !empty($thread['context'])) {
                $kind = ['label' => (string)$thread['context']['label'],
                    'variant' => self::kind_variant((string)$thread['context']['kind'])];
            } else if ($thread['thread_type'] === 'group') {
                $kind = ['label' => self::tr('communicationux2_group_members', count($thread['participants'])),
                    'variant' => 'mint'];
            }
            $preview = '';
            if (!empty($thread['preview'])) {
                $authorid = (int)$thread['preview']['author_id'];
                $prefix = '';
                if ($authorid === (int)$USER->id) {
                    $prefix = self::tr('communicationux2_you') . ': ';
                } else if ($thread['thread_type'] !== 'direct' && isset($data['employees'][$authorid])) {
                    $prefix = preg_split('/\s+/u', trim((string)$data['employees'][$authorid]))[0] . ': ';
                }
                $preview = $prefix . (string)$thread['preview']['body'];
            }
            $avatarsource = $isobject && !empty($thread['context']) ? (string)$thread['context']['label'] : (string)$thread['title'];
            $unread = max(0, (int)$thread['unread_count']);
            $items[] = [
                'uuid' => (string)$thread['uuid'], 'title' => (string)$thread['title'],
                'url' => self::url($company, ['tab' => $section, 'thread' => $thread['uuid'], 'q' => $search,
                    'filter' => $filter])->out(false),
                'active' => !empty($data['selected']) && $data['selected']['uuid'] === $thread['uuid'],
                'initials' => self::initials($avatarsource),
                'avatarvariant' => $isobject ? 'object' : ($thread['thread_type'] === 'group' ? 'group' : 'person'),
                'kind' => $kind, 'preview' => $preview,
                'time' => self::short_time((int)$thread['last_message_at']),
                'unread' => $unread > 0 ? $unread : null,
                'unreadtitle' => self::tr('communication_unreadthreads'),
                'mention' => !empty($thread['has_unread_mention']),
                'mentiontitle' => self::tr('communicationcenter_unread_mention_signal'),
            ];
        }
        $empty = null;
        if (!$items) {
            $other = $section === 'messages' ? 'discussions' : 'messages';
            $othertotal = (int)($sections[$other]['total'] ?? 0);
            if ($search !== '') {
                $empty = ['title' => self::tr('communicationux2_empty_search_title'),
                    'text' => self::tr('communicationux2_empty_search_text', $search),
                    'action' => ['label' => self::tr('communicationux2_reset_search'),
                        'url' => self::url($company, ['tab' => $section, 'filter' => $filter])->out(false)]];
            } else if ($filter !== 'all') {
                $empty = ['title' => self::tr('communicationux2_empty_' . $filter . '_title'),
                    'text' => self::tr('communicationux2_empty_' . $filter . '_text'),
                    'action' => ['label' => self::tr('communicationux2_show_all'),
                        'url' => self::url($company, ['tab' => $section, 'filter' => 'all'])->out(false)]];
            } else {
                $empty = ['title' => self::tr('communicationux2_empty_' . $section . '_title'),
                    'text' => self::tr('communicationux2_empty_' . $section . '_text'),
                    'action' => $othertotal > 0 ? ['label' => self::tr('communicationux2_goto_' . $other, $othertotal),
                        'url' => self::url($company, ['tab' => $other])->out(false)] : null,
                    'newconversation' => $section === 'messages'];
            }
        }
        return [
            'label' => self::tr('communicationcenter_conversations_title'),
            'filterslabel' => self::tr('communicationux_filters'),
            'search' => ['hidden' => self::hidden(['uuid' => $company, 'tab' => $section, 'filter' => $filter]),
                'value' => $search, 'label' => self::tr('tui_search'),
                'placeholder' => self::tr($section === 'discussions' ? 'communicationux2_search_objects' :
                    'communicationux2_search_people')],
            'chips' => $chips, 'items' => $items, 'empty' => $empty,
            'unreadtotal' => max(0, (int)($data['counts']['unread_threads'] ?? 0)),
            'unreadlabel' => self::tr('communication_unreadthreads'),
        ];
    }

    private static function thread(string $company, array $data, int $actor): array {
        $thread = $data['selected'];
        $tab = (string)$data['tab'];
        $isobject = $thread['thread_type'] === 'object' && !empty($thread['context']);
        $meta = $data['selected_meta'] ?? null;
        $kindline = ['pill' => null, 'status' => '', 'due' => '', 'text' => ''];
        if ($isobject) {
            $kindline['pill'] = ['label' => (string)$thread['context']['label'],
                'variant' => self::kind_variant((string)$thread['context']['kind'])];
            if ($meta && $meta['status'] !== '') { $kindline['status'] = (string)$meta['status']; }
            $due = $meta ? self::date_text((string)$meta['due']) : '';
            if ($due !== '') { $kindline['due'] = self::tr('communicationux2_due', $due); }
        } else {
            $kindline['text'] = $thread['thread_type'] === 'group' ?
                self::tr('communicationux2_group_people', count($thread['participants'])) : self::tr('communicationux2_direct');
        }
        $openlink = null;
        if ($isobject && (string)$thread['context']['url'] !== '') {
            $kind = (string)$thread['context']['kind'];
            $openlink = ['url' => (string)$thread['context']['url'], 'label' => self::tr($kind === 'task' ?
                'communicationcenter_opentask' : ($kind === 'battleplan' ? 'communicationcenter_openplan' :
                'communication_opencontext'))];
        }
        $messagequery = trim(\core_text::substr((string)($data['message_search'] ?? ''), 0, 200));
        $visiblemessages = $data['messages'];
        $history = $data['history'] ?? ['total' => count($visiblemessages), 'page' => 0, 'pages' => 1];
        $pages = max(1, (int)$history['pages']);
        $page = (int)$history['page'];

        // Timeline: messages plus task History events inside the page's time window (never during a search).
        $timeline = [];
        foreach ($visiblemessages as $i => $message) {
            $timeline[] = ['time' => (int)$message['time_created'], 'order' => 1, 'seq' => $i, 'message' => $message];
        }
        if ($messagequery === '') {
            $lower = $visiblemessages && $page < $pages - 1 ? (int)$visiblemessages[0]['time_created'] : PHP_INT_MIN;
            $upper = $visiblemessages && $page > 0 ?
                (int)$visiblemessages[count($visiblemessages) - 1]['time_created'] : PHP_INT_MAX;
            foreach (($data['events'] ?? []) as $i => $event) {
                $time = (int)$event['time_created'];
                if ($time < $lower || $time > $upper) { continue; }
                $timeline[] = ['time' => $time, 'order' => 0, 'seq' => $i, 'event' => $event];
            }
        }
        usort($timeline, static function(array $a, array $b): int {
            return [$a['time'], $a['order'], $a['seq']] <=> [$b['time'], $b['order'], $b['seq']];
        });
        // "New messages" divider: before the first of the thread's unread messages from others on the latest page.
        $newfrom = null;
        $unread = (int)($thread['unread_count'] ?? 0);
        if ($unread > 0 && $page === 0 && $messagequery === '') {
            $others = array_values(array_filter($visiblemessages, static function(array $m) use ($actor): bool {
                return (int)$m['author_id'] !== $actor && $m['status'] === 'active';
            }));
            if (count($others) >= $unread) { $newfrom = $others[count($others) - $unread]['uuid']; }
        }
        $writer = !isset($thread['is_participant']) || !empty($thread['is_participant']);
        $entries = [];
        $previousday = null;
        foreach ($timeline as $entry) {
            $day = business_date_service::day((int)$entry['time']);
            if ($day !== $previousday) {
                $entries[] = ['isday' => true, 'label' => self::day_label((int)$entry['time'])];
                $previousday = $day;
            }
            if (isset($entry['event'])) {
                $entries[] = ['isevent' => true] + self::event_line($entry['event']);
                continue;
            }
            if ($newfrom !== null && $entry['message']['uuid'] === $newfrom) {
                $entries[] = ['isnew' => true, 'label' => self::tr('communicationux2_new_messages')];
            }
            $entries[] = ['ismessage' => true] +
                self::message($company, $thread, $entry['message'], $data['employees'], $tab, $writer, $actor);
        }
        $pager = null;
        if ($pages > 1) {
            $base = ['tab' => $tab, 'thread' => $thread['uuid']];
            if ($messagequery !== '') { $base['mq'] = $messagequery; }
            $pager = ['label' => self::tr('communicationcenter_history_navigation'),
                'text' => get_string('communicationcenter_history_page', 'local_kbacompany',
                    (object)['current' => $page + 1, 'total' => $pages]),
                'older' => $page + 1 < $pages ? ['url' => self::url($company, $base + ['mpage' => $page + 1])->out(false),
                    'label' => self::tr('communicationcenter_older_messages')] : null,
                'newer' => $page > 0 ? ['url' => self::url($company, $base + ['mpage' => $page - 1])->out(false),
                    'label' => self::tr('communicationcenter_newer_messages')] : null];
        }
        $model = [
            'uuid' => (string)$thread['uuid'], 'title' => (string)$thread['title'], 'kindline' => $kindline,
            'backurl' => self::url($company, ['tab' => $tab])->out(false), 'backlabel' => self::tr('communicationcenter_conversations_title'),
            'openlink' => $openlink,
            'detailslabel' => self::tr($isobject ? 'communicationux2_details' : 'communication_participants'),
            'detailstitle' => self::tr('communicationcenter_context_title'),
            'searchlabel' => self::tr('communicationcenter_search_messages'),
            'messagesearch' => ['hidden' => self::hidden(['uuid' => $company, 'tab' => $tab, 'thread' => $thread['uuid']]),
                'value' => $messagequery, 'hasquery' => $messagequery !== '',
                'clearurl' => self::url($company, ['tab' => $tab, 'thread' => $thread['uuid']])->out(false),
                'clearlabel' => self::tr('communicationcenter_clear_search'),
                'count' => $messagequery !== '' ? get_string('communicationcenter_search_count', 'local_kbacompany',
                    (int)$history['total']) : ''],
            'entries' => $entries,
            'nomessages' => $entries ? '' : self::tr($messagequery !== '' ? 'communicationcenter_search_empty' :
                'communicationcenter_no_messages'),
            'feedlabel' => self::tr('communicationcenter_selectthread'),
            'pager' => $pager,
        ];
        if (!$writer) {
            if (empty($thread['can_join'])) {
                $model['readonly'] = self::tr('communicationcenter_readonly_note');
            } else {
                // Readers join explicitly before writing; reading never joins silently.
                $model['join'] = ['hidden' => array_merge(self::post_base($company, 'createobject'), self::hidden([
                    'objectkind' => $thread['context']['kind'] ?? $thread['context_kind'],
                    'objectuuid' => $thread['context_uuid'], 'objectrevision' => $thread['context_revision']])),
                    'note' => self::tr('communicationcenter_reader_note'), 'label' => self::tr('communicationcenter_join_discussion')];
            }
            return $model;
        }
        $model['compose'] = self::compose($company, $data, $thread, $isobject, $actor);
        return $model;
    }

    private static function compose(string $company, array $data, array $thread, bool $isobject, int $actor): array {
        $tab = (string)$data['tab'];
        $replytarget = null;
        $replyuuid = (string)($data['reply'] ?? '');
        foreach ($data['messages'] as $candidate) {
            if ($replyuuid !== '' && $candidate['uuid'] === $replyuuid && $candidate['status'] === 'active') {
                $replytarget = $candidate; break;
            }
        }
        $mentions = [];
        foreach ($thread['participants'] as $participant) {
            if ((int)$participant['user_id'] !== $actor) {
                $mentions[(int)$participant['user_id']] = $participant['name'];
            }
        }
        $hidden = self::post_base($company, 'send', $thread['uuid']);
        $reply = null;
        if ($replytarget) {
            $hidden = array_merge($hidden, self::hidden(['replytouuid' => $replytarget['uuid']]));
            $reply = ['label' => self::tr('communication_replyingto'), 'author' => (string)$replytarget['author_name'],
                'excerpt' => \core_text::substr(preg_replace('/\s+/u', ' ', trim((string)$replytarget['body'])), 0, 160),
                'cancelurl' => self::url($company, ['tab' => $tab, 'thread' => $thread['uuid']])->out(false),
                'cancellabel' => self::tr('communication_cancelreply')];
        }
        return [
            'hidden' => $hidden, 'reply' => $reply,
            'maxbytes' => (int)get_max_upload_file_size(),
            'msgempty' => self::tr('communication_body_or_file'), 'msgtoolarge' => self::tr('communication_filesize'),
            'bodylabel' => self::tr('communication_message'),
            'placeholder' => self::tr('communicationux2_compose_placeholder'),
            'fileslabel' => self::tr('communicationux2_files'), 'filetitle' => self::tr('communication_attachment'),
            'fileremove' => self::tr('communication_file_remove'),
            'audience' => $isobject ? self::tr('communicationux2_audience_object') : '',
            'mentions' => $mentions ? ['label' => self::tr('communication_mentions'),
                'options' => self::options($mentions), 'help' => self::tr('communication_mentions_help'),
                'inlinehelp' => self::tr('communication_mentions_inline_help')] : null,
            'sendlabel' => self::tr('communication_send'),
        ];
    }

    /** Read-only task History event inside a task discussion; never a message. */
    private static function event_line(array $event): array {
        $key = 'taskdiscussion_event_' . (string)$event['action'];
        $label = get_string_manager()->string_exists($key, 'local_kbacompany') ? self::tr($key) : self::tr('tui_history_event');
        $status = '';
        if ($event['new_status'] !== '' && $event['new_status'] !== $event['old_status'] &&
                in_array((string)$event['new_status'], task_service::STATUSES, true)) {
            $status = self::tr('taskcenter_status_' . $event['new_status']);
        }
        return ['label' => $label, 'status' => $status, 'actor' => trim((string)$event['actor_name']),
            'time' => userdate((int)$event['time_created'], '%H:%M'), 'reason' => trim((string)($event['reason'] ?? ''))];
    }

    private static function message(string $company, array $thread, array $message, array $employees, string $tab,
            bool $writer, int $actor): array {
        $active = $message['status'] === 'active';
        $own = (int)$message['author_id'] === $actor;
        $mentionnames = [];
        foreach (($message['mentions'] ?? []) as $userid) {
            if (isset($employees[(int)$userid])) { $mentionnames[] = '@' . $employees[(int)$userid]; }
        }
        $files = [];
        foreach ($active ? ($message['attachments'] ?? []) : [] as $attachment) {
            $label = $attachment['label'] !== '' ? $attachment['label'] : $attachment['value'];
            $files[] = ['name' => (string)$label, 'url' => (string)$attachment['url'],
                'size' => isset($attachment['size']) ? display_size((int)$attachment['size']) : ''];
        }
        $quote = null;
        if (!empty($message['reply_to'])) {
            $quote = ['author' => (string)$message['reply_to']['author_name'],
                'text' => $message['reply_to']['status'] === 'active' ? (string)$message['reply_to']['excerpt'] :
                    self::tr('communication_messagewithdrawn')];
        }
        $actions = [];
        if ($writer && $active) {
            $actions[] = ['url' => self::url($company, ['tab' => $tab, 'thread' => $thread['uuid'],
                'reply' => $message['uuid']])->out(false), 'label' => self::tr('communication_reply')];
        }
        if ($message['task_uuid'] !== '') {
            $actions[] = ['url' => task_ui_service::url($company, ['task' => $message['task_uuid']])->out(false),
                'label' => self::tr('communication_opentask')];
        } else if ($writer && $active) {
            $actions[] = ['url' => self::url($company, ['tab' => $tab, 'thread' => $thread['uuid'],
                'convert' => $message['uuid']])->out(false), 'label' => self::tr('communication_createtask')];
        }
        $decision = null;
        if ($writer && $active && $thread['context_kind'] === 'meeting') {
            $decision = ['hidden' => array_merge(self::post_base($company, 'createdecision', $thread['uuid']),
                self::hidden(['messageuuid' => $message['uuid']])), 'label' => self::tr('communication_createdecision')];
        }
        $edit = null;
        // Authors may correct or withdraw their own active messages; linked tasks stay protected server-side.
        if ($own && $active && $message['task_uuid'] === '') {
            $basehash = hash('sha256', $message['body'] . "\n" . $message['status']);
            $common = self::hidden(['messageuuid' => $message['uuid'], 'expectedhash' => $basehash, 'tab' => $tab]);
            $edit = [
                'edithidden' => array_merge(self::post_base($company, 'editmessage', $thread['uuid']), $common),
                'deletehidden' => array_merge(self::post_base($company, 'withdrawmessage', $thread['uuid']), $common),
                'editlabel' => self::tr('communicationux2_edit'), 'deletelabel' => self::tr('communication_withdrawmessage'),
                'savelabel' => self::tr('communication_saveedit'), 'bodylabel' => self::tr('communication_message'),
                'deletenotice' => self::tr('communication_deletenotice'),
                'confirmlabel' => self::tr('communication_confirmdelete'),
            ];
        }
        return [
            'uuid' => (string)$message['uuid'], 'own' => $own, 'withdrawn' => !$active,
            'author' => $own ? self::tr('communicationux2_you') : (string)$message['author_name'],
            'time' => userdate((int)$message['time_created'], '%H:%M'),
            'fulltime' => userdate((int)$message['time_created']),
            'edited' => !empty($message['edited']) && $active ? self::tr('communication_edited') : '',
            'quote' => $quote,
            'bodyhtml' => $active ? format_text($message['body'], FORMAT_PLAIN) : '',
            'withdrawntext' => $active ? '' : self::tr('communication_messagewithdrawn'),
            'body' => (string)$message['body'],
            'mentions' => $mentionnames ? implode(', ', $mentionnames) : '',
            'files' => $files, 'hasfiles' => !empty($files), 'actions' => $actions, 'decision' => $decision, 'edit' => $edit,
            'hasactions' => $actions || $decision || $edit,
        ];
    }

    /**
     * Daily report notifications carry no thread or task: link to the reviewer queue or the author's history.
     * The daily report page re-checks company, membership and review rights; the link itself grants nothing.
     * @return array|null [moodle_url, string key] or null for other kinds
     */
    public static function daily_report_target(string $company, string $kind): ?array {
        if ($kind === 'dailyreport_submitted' || $kind === 'dailyreport_copy') {
            return [daily_report_ui_service::url($company, ['tab' => 'review']), 'dailyreports_tab_review'];
        }
        if ($kind === 'dailyreport_decision') {
            return [daily_report_ui_service::url($company, ['tab' => 'history']), 'dailyreports_tab_history'];
        }
        return null;
    }

    /** Type chip of a notification kind: [string key, variant, icon]. */
    public static function notification_type(string $kind): array {
        if ($kind === 'mention') { return ['communicationux2_type_mention', 'sky', '@']; }
        if (in_array($kind, ['message', 'thread_invite'], true)) { return ['communicationux2_type_message', 'sky', '✉']; }
        if (in_array($kind, ['task_created', 'calendar_task_due'], true)) { return ['communicationux2_type_task', 'mint', '✓']; }
        if ($kind === 'calendar_meeting_hour') { return ['communicationux2_type_meeting', 'sand', '◷']; }
        if (strpos($kind, 'calendar_') === 0) { return ['communicationux2_type_dayplan', 'sand', '◷']; }
        if (strpos($kind, 'dailyreport_') === 0) { return ['communicationux2_type_dailyreport', 'mint', '⚑']; }
        return ['communicationux2_type_other', 'sky', '•'];
    }

    private static function notifications(string $company, array $data): array {
        $onlyunread = ($data['filter'] ?? 'all') === 'unread';
        $groups = ['today' => [], 'earlier' => []];
        $today = business_date_service::day(time());
        $unread = 0;
        foreach (array_slice($data['notifications'], 0, 30) as $notification) {
            if (!$notification['is_read']) { $unread++; }
            if ($onlyunread && $notification['is_read']) { continue; }
            $key = 'communication_notification_' . $notification['kind'];
            $title = get_string_manager()->string_exists($key, 'local_kbacompany') ? self::tr($key) :
                self::tr('communication_notification');
            [$typekey, $variant, $icon] = self::notification_type((string)$notification['kind']);
            $links = [];
            if ($notification['thread_uuid'] !== '') {
                $links[] = ['url' => self::url($company, ['thread' => $notification['thread_uuid']])->out(false),
                    'label' => self::tr('communicationux2_open_conversation')];
            }
            $target = self::daily_report_target($company, (string)$notification['kind']);
            if ($target) { $links[] = ['url' => $target[0]->out(false), 'label' => self::tr($target[1])]; }
            if (strpos((string)$notification['kind'], 'calendar_') === 0) {
                $links[] = ['url' => (new \moodle_url('/local/kbacompany/calendar.php', ['uuid' => $company]))->out(false),
                    'label' => self::tr('communicationux2_open_calendar')];
            }
            $time = (int)$notification['time_created'];
            $istoday = business_date_service::day($time) === $today;
            $groups[$istoday ? 'today' : 'earlier'][] = [
                'uuid' => (string)$notification['uuid'], 'read' => !empty($notification['is_read']),
                'type' => self::tr($typekey), 'variant' => $variant, 'icon' => $icon,
                'title' => $title, 'subtitle' => (string)$notification['title'], 'links' => $links,
                'time' => $istoday ? userdate($time, '%H:%M') : userdate($time, '%d.%m.%Y, %H:%M'),
                'markread' => empty($notification['is_read']) ? ['hidden' => array_merge(
                    self::post_base($company, 'readnotification'),
                    self::hidden(['notification' => $notification['uuid'], 'tab' => 'notifications'])),
                    'label' => self::tr('communicationux2_mark_read')] : null,
                'readlabel' => self::tr('communicationux_read'),
                'unreadlabel' => self::tr('communicationux_unread'),
            ];
        }
        $out = [];
        foreach (['today' => 'communicationux2_group_today', 'earlier' => 'communicationux2_group_earlier'] as $key => $label) {
            if ($groups[$key]) { $out[] = ['label' => self::tr($label), 'items' => $groups[$key]]; }
        }
        return [
            'title' => self::tr('communicationcenter_tab_notifications'), 'unread' => $unread ?: null,
            'onlyunread' => ['active' => $onlyunread, 'label' => self::tr('communicationux2_only_unread'),
                'url' => self::url($company, ['tab' => 'notifications', 'filter' => $onlyunread ? 'all' : 'unread'])->out(false)],
            // mark-all-read is withheld from this candidate: the prototype only proposed it and the
            // owner has not approved a bulk action. The POST endpoint is left intact for a later decision.
            'settings' => ['url' => (new \moodle_url('/local/kbacompany/calendar_notifications.php', ['uuid' => $company]))->out(false),
                'label' => self::tr('communicationux2_notification_settings')],
            'groups' => $out,
            'empty' => $out ? '' : self::tr($onlyunread ? 'communicationux2_no_unread_notifications' :
                'communication_nonotifications'),
            'feedlabel' => self::tr('communication_notifications'),
        ];
    }

    /**
     * Day feed -> report draft: own submitted task results of one day. The "Add to report" form posts only the event id
     * (with sesskey) to the report page, which re-reads the result on the server. No button for a sent report or a
     * future day (both decided by the page from the store); the result text is shown as stored in the submit event.
     */
    private static function day_feed(string $company, array $feed): array {
        $day = (string)$feed['day'];
        $today = (string)$feed['today'];
        $sent = !empty($feed['sent']);
        $canadd = !empty($feed['canadd']);
        $reporturl = new \moodle_url('/local/kbacompany/daily_report.php', ['uuid' => $company, 'day' => $day]);
        $items = [];
        foreach ($feed['results'] as $event) {
            $status = (string)$event->currentstatus;
            $statuskey = 'taskcenter_status_' . $status;
            $statuslabel = get_string_manager()->string_exists($statuskey, 'local_kbacompany') ? self::tr($statuskey) : $status;
            $result = (string)$event->result;
            $items[] = [
                'id' => (int)$event->id,
                'time' => userdate((int)$event->timecreated, '%H:%M'),
                'fulltime' => userdate((int)$event->timecreated),
                'title' => (string)$event->title,
                'taskurl' => (new \moodle_url('/local/kbacompany/tasks.php',
                    ['uuid' => $company, 'task' => (string)$event->taskuuid]))->out(false),
                'opentask' => self::tr('dailyreports_feed_open_task'),
                'result' => $result,
                'noresult' => $result === '' ? self::tr('dailyreport_missingresult') : '',
                'submitted' => self::tr('dailyreports_feed_submitted'),
                'now' => self::tr('dailyreports_feed_now', $statuslabel),
                'nowvariant' => $status === 'done' ? 'mint' : ($status === 'returned' ? 'sand' : 'sky'),
                'addform' => $canadd ? ['url' => (new \moodle_url('/local/kbacompany/daily_report.php'))->out(false),
                    'hidden' => self::hidden(['sesskey' => sesskey(), 'uuid' => $company, 'day' => $day, 'source' => 'feed',
                        'addevent' => (string)(int)$event->id]),
                    'label' => self::tr('dailyreports_feed_add')] : null,
            ];
        }
        $page = (int)$feed['page'];
        $pages = (int)ceil((int)$feed['total'] / daily_report_service::PAGE_SIZE);
        $pageurl = static function(int $p) use ($company, $day): string {
            return self::url($company, ['tab' => 'dayfeed', 'day' => $day, 'fpage' => $p])->out(false);
        };
        return [
            'title' => self::tr('dailyreports_feed_title'),
            'intro' => self::tr('dailyreports_feed_intro'),
            'total' => (int)$feed['total'] ?: null,
            'form' => ['url' => self::url($company)->out(false), 'hidden' => self::hidden(['uuid' => $company, 'tab' => 'dayfeed']),
                'label' => self::tr('dailyreports_feed_day'), 'value' => $day, 'max' => $today,
                'show' => self::tr('dailyreports_feed_show'),
                'today' => $day !== $today ? ['url' => self::url($company, ['tab' => 'dayfeed'])->out(false),
                    'label' => self::tr('dailyreports_feed_today')] : null],
            'timezone' => self::tr('dailyreport_timezone') . ': ' . (string)$feed['timezone'],
            'report' => ['url' => $reporturl->out(false), 'label' => self::tr('dailyreports_feed_open_report')],
            'sent' => $sent && $items ? self::tr('dailyreports_feed_sent') : '',
            'items' => $items,
            'empty' => $items ? null : ['title' => self::tr('dailyreports_feed_empty_title'),
                'text' => self::tr('dailyreports_feed_empty_text'), 'action' => null],
            'pager' => $pages > 1 ? ['label' => self::tr('dailyreports_feed_pages'),
                'prev' => $page > 0 ? ['url' => $pageurl($page - 1), 'label' => self::tr('dailyreports_feed_prev')] : null,
                'next' => $page + 1 < $pages ? ['url' => $pageurl($page + 1), 'label' => self::tr('dailyreports_feed_next')] : null,
                'text' => ($page + 1) . ' / ' . $pages] : null,
        ];
    }

    private static function context_panel(string $company, array $data, ?array $objectrequest, int $actor,
            string $convertmessage): array {
        $thread = $data['selected'];
        $meta = $data['selected_meta'] ?? null;
        $model = ['title' => self::tr('communicationcenter_context_title'),
            'closelabel' => self::tr('bmscomm3_closecontext')];
        $object = null;
        if ($thread && !empty($thread['context'])) {
            $context = $thread['context'];
            $object = ['start' => null, 'kind' => (string)$context['label'], 'title' => (string)$context['title'],
                'status' => $meta ? (string)$meta['status'] : '', 'due' => $meta ? self::date_text((string)$meta['due']) : '',
                'open' => (string)$context['url'] !== '' ? ['url' => (string)$context['url'],
                    'label' => self::tr('communication_opencontext')] : null];
        } else if ($objectrequest) {
            $object = ['open' => null, 'status' => '', 'due' => '',
                'kind' => (string)$objectrequest['label'], 'title' => (string)$objectrequest['title'],
                'start' => ['hidden' => array_merge(self::post_base($company, 'createobject'), self::hidden([
                    'objectkind' => $objectrequest['kind'], 'objectuuid' => $objectrequest['uuid'],
                    'objectrevision' => $objectrequest['revision']])),
                    'label' => self::tr('communicationcenter_startdiscussion')]];
        }
        if ($object) {
            $object += ['kindlabel' => self::tr('communicationux2_kv_type'), 'statuslabel' => self::tr('communicationux2_kv_status'),
                'duelabel' => self::tr('communicationux2_kv_due')];
        }
        $model['object'] = $object;
        if ($thread) {
            $isowner = false;
            $ids = [];
            foreach ($thread['participants'] as $participant) {
                if ((int)$participant['user_id'] === $actor && $participant['role'] === 'owner') { $isowner = true; }
                $ids[] = (int)$participant['user_id'];
            }
            $isgroup = $thread['thread_type'] === 'group';
            $people = [];
            foreach ($thread['participants'] as $participant) {
                $userid = (int)$participant['user_id'];
                $people[] = ['name' => (string)$participant['name'], 'initials' => self::initials((string)$participant['name']),
                    'owner' => $participant['role'] === 'owner' ? self::tr('communication_owner') : '',
                    'remove' => $isgroup && $isowner && $userid !== $actor ? ['hidden' => array_merge(
                        self::post_base($company, 'removeparticipant', $thread['uuid']),
                        self::hidden(['participantid' => $userid])), 'label' => self::tr('communication_removeparticipant')] : null];
            }
            $model['people'] = ['label' => self::tr('communication_participants'), 'count' => count($people), 'items' => $people];
            if ($isgroup && in_array($actor, $ids, true)) {
                $model['leave'] = ['hidden' => self::post_base($company, 'leavethread', $thread['uuid']),
                    'label' => self::tr('communication_leave'), 'notice' => self::tr('communication_leave_notice')];
            }
            if ($isowner && $thread['thread_type'] !== 'direct') {
                $available = [];
                foreach ($data['employees'] as $id => $name) {
                    if ((int)$id !== $actor && !in_array((int)$id, $ids, true)) { $available[(int)$id] = $name; }
                }
                if ($available) {
                    // Never name this key "add": Mustache calls a method of that name on object frames,
                    // and Moodle's mustache_helper_collection::add() takes two arguments, so {{#add}}
                    // throws ArgumentCountError at render time.
                    $model['addperson'] = ['hidden' => self::post_base($company, 'addparticipant', $thread['uuid']),
                        'options' => self::options($available), 'label' => self::tr('communication_addparticipant')];
                }
            }
            if ($convertmessage !== '') {
                foreach ($data['messages'] as $candidate) {
                    if ($candidate['uuid'] === $convertmessage && $candidate['status'] === 'active' &&
                            $candidate['task_uuid'] === '') {
                        $model['taskform'] = self::task_form($company, $thread, $candidate, $data['employees'], $actor);
                        break;
                    }
                }
            }
        }
        return $model;
    }

    private static function new_conversation(string $company, array $employees, int $actor): ?array {
        $people = [];
        foreach ($employees as $id => $name) {
            if ((int)$id !== $actor) { $people[(int)$id] = $name; }
        }
        if (!$people) { return null; }
        return [
            'label' => self::tr('communicationux2_newconversation'),
            'hidden' => self::post_base($company, 'createthread'),
            'typelabel' => self::tr('communication_type'),
            'types' => self::options(['direct' => self::tr('communication_type_direct'),
                'group' => self::tr('communication_type_group')], 'direct'),
            'titlelabel' => self::tr('communication_thread'), 'titlehint' => self::tr('communicationcenter_group_title_hint'),
            'peoplelabel' => self::tr('communication_participants'), 'people' => self::options($people),
            'peoplehelp' => self::tr('communicationcenter_participants_help'),
            'createlabel' => self::tr('communication_create'), 'closelabel' => self::tr('bmscomm3_closecontext'),
        ];
    }

    private static function task_form(string $company, array $thread, array $message, array $employees, int $actor): array {
        $streams = [];
        foreach (task_service::STREAMS as $stream) { $streams[$stream] = self::tr('taskcenter_stream_' . $stream); }
        $priorities = [];
        foreach (task_service::PRIORITIES as $priority) { $priorities[$priority] = self::tr('taskcenter_priority_' . $priority); }
        return [
            'title' => self::tr('communication_taskfrommessage'),
            'hidden' => array_merge(self::post_base($company, 'createtask', $thread['uuid']),
                self::hidden(['messageuuid' => $message['uuid'], 'startdate' => '', 'planminutes' => 0])),
            'tasktitle' => \core_text::substr(trim(preg_replace('/\s+/u', ' ', $message['body'])), 0, 120),
            'description' => (string)$message['body'],
            'labels' => ['title' => self::tr('taskcenter_task'), 'product' => self::tr('taskcenter_product'),
                'description' => self::tr('taskcenter_description'), 'assignee' => self::tr('taskcenter_assignee'),
                'reviewer' => self::tr('taskcenter_reviewer'), 'stream' => self::tr('taskcenter_stream'),
                'priority' => self::tr('taskcenter_priority'), 'due' => self::tr('taskcenter_duedate')],
            'assignees' => self::options($employees, $actor),
            'reviewers' => self::options([0 => self::tr('taskcenter_noreviewer')] + $employees, 0),
            'streams' => self::options($streams, 'operate'),
            'priorities' => self::options($priorities, 'normal'),
            'submit' => self::tr('communication_createtask'),
        ];
    }

    /** Kept for compatibility with older callers. */
    public static function screen(string $company, array $data, string $search): string {
        return task_ui_service::note(self::tr('communicationcenter_intro'));
    }
}
