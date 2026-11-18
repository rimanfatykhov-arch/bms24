<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Render-only "Discussion" tab of a Battle Plan card. Data and ACL come from battle_plan_discussion_service; editing or
 * withdrawing own messages stays in the Communication Center (same canonical thread).
 */
final class battle_plan_discussion_ui_service {
    private static function tr(string $key): string {
        return get_string($key, 'local_kbacompany');
    }

    private static function note(string $text): string {
        return \html_writer::tag('p', s($text), ['class' => 'bms-bpu-note']);
    }

    /** Quoted original of a reply; a withdrawn original shows only that it was withdrawn. */
    private static function quote(?array $quote): string {
        if (!$quote) { return ''; }
        $text = $quote['status'] === 'active' ? (string)$quote['excerpt'] : self::tr('communication_messagewithdrawn');
        return \html_writer::tag('blockquote', \html_writer::tag('strong', s((string)$quote['author_name'])) .
            \html_writer::div(s($text)), ['class' => 'bms-tui-message-quote']);
    }

    private static function plan_url(string $company, string $plan, array $extra = []): \moodle_url {
        return new \moodle_url('/local/kbacompany/battle_plan.php',
            ['uuid' => $company, 'plan' => $plan, 'view' => 'detail', 'tab' => 'discussion'] + $extra);
    }

    private static function message(string $company, string $plan, array $msg, bool $canpost): string {
        $active = $msg['status'] === 'active';
        $text = $active ? (trim((string)$msg['body']) !== '' ?
            \html_writer::div(format_text($msg['body'], FORMAT_PLAIN), 'bms-tui-prose') : '') :
            self::note(self::tr('communication_messagewithdrawn'));
        if ($active && !empty($msg['edited'])) { $text .= self::note(self::tr('communication_edited')); }
        $files = '';
        foreach (($active ? ($msg['attachments'] ?? []) : []) as $attachment) {
            $label = (string)($attachment['label'] !== '' ? $attachment['label'] : $attachment['value']);
            $files .= !empty($attachment['url']) ?
                \html_writer::link($attachment['url'], s($label), ['class' => 'btn btn-sm btn-outline-secondary']) :
                \html_writer::span(s($label), 'badge badge-light');
        }
        $actions = '';
        if ($active && $canpost) {
            $actions = \html_writer::link(self::plan_url($company, $plan, ['reply' => $msg['uuid']]),
                s(self::tr('communication_reply')));
        }
        return \html_writer::tag('article',
            \html_writer::tag('strong', s($msg['author_name'])) . self::note(userdate((int)$msg['time_created'])) .
            self::quote($msg['reply_to'] ?? null) . $text .
            ($files !== '' ? \html_writer::div($files, 'bms-tui-message-files') : '') .
            ($actions !== '' ? \html_writer::div($actions, 'bms-tui-message-actions') : ''),
            ['class' => 'bms-tui-message', 'id' => 'plan-message-' . $msg['uuid']]);
    }

    /** The whole discussion tab. $reply is a presentation-only target; the service re-validates it on POST. */
    public static function render(string $company, array $d, string $reply = '', string $error = ''): string {
        $plan = (string)$d['plan']['uuid'];
        $canpost = !empty($d['can_post']);
        $html = $error !== '' ? \html_writer::div(s($error), 'alert alert-danger', ['role' => 'alert']) : '';
        $list = '';
        foreach ($d['messages'] as $msg) { $list .= self::message($company, $plan, $msg, $canpost); }
        $html .= $list !== '' ? \html_writer::div($list, 'bms-bpu-discussion-messages') :
            self::note(self::tr('bpu_discussion_empty'));
        if (!$canpost) {
            $html .= self::note(self::tr('bpu_discussion_readonly'));
        } else {
            if (empty($d['is_participant']) && $d['thread_uuid'] !== '') {
                $html .= self::note(self::tr('bpu_discussion_reader'));
            }
            $hidden = ['uuid' => $company, 'plan' => $plan, 'action' => 'post', 'sesskey' => sesskey()];
            $target = '';
            foreach ($d['messages'] as $msg) {
                if ($reply !== '' && $msg['uuid'] === $reply && $msg['status'] === 'active') {
                    $hidden['replytouuid'] = $msg['uuid'];
                    $target = \html_writer::div(s(self::tr('communication_replyingto')) . self::quote([
                            'author_name' => $msg['author_name'], 'status' => 'active',
                            'excerpt' => \core_text::substr(preg_replace('/\s+/u', ' ', trim((string)$msg['body'])), 0, 160)]) .
                        \html_writer::link(self::plan_url($company, $plan), s(self::tr('communication_cancelreply'))),
                        'bms-tui-reply-target');
                    break;
                }
            }
            $fields = '';
            foreach ($hidden as $name => $value) {
                $fields .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]);
            }
            $fields .= $target;
            $fields .= \html_writer::label(s(self::tr('communication_message')), 'bpu_discussion_message');
            $fields .= \html_writer::tag('textarea', '', ['id' => 'bpu_discussion_message', 'name' => 'message', 'rows' => 3,
                'maxlength' => 20000, 'class' => 'form-control']);
            $fields .= \html_writer::label(s(self::tr('communication_attachment')), 'bpu_discussion_attachment');
            $fields .= \html_writer::empty_tag('input', ['type' => 'file', 'id' => 'bpu_discussion_attachment',
                'name' => 'discussionattachment[]', 'class' => 'form-control']);
            $fields .= \html_writer::tag('button', s(self::tr('communication_send')),
                ['type' => 'submit', 'class' => 'btn btn-primary']);
            $html .= \html_writer::tag('form', $fields, ['method' => 'post', 'enctype' => 'multipart/form-data',
                'action' => (new \moodle_url('/local/kbacompany/battle_plan_discussion.php'))->out(false),
                'class' => 'bms-bpu-discussion-compose', 'data-bms-plan-discussion-composer' => '1']);
        }
        $centerparams = ['uuid' => $company, 'tab' => 'discussions'];
        if ($d['thread_uuid'] !== '') {
            $centerparams['thread'] = $d['thread_uuid'];
        } else {
            $centerparams += ['objectkind' => battle_plan_discussion_service::KIND, 'objectuuid' => $plan];
        }
        $html .= \html_writer::div(\html_writer::link(new \moodle_url('/local/kbacompany/communication_center.php',
            $centerparams), s(self::tr('taskcenter_discussion_open'))), 'bms-bpu-discussion-center');
        return \html_writer::tag('section', \html_writer::tag('h2', s(self::tr('bpu_tab_discussion'))) . $html,
            ['class' => 'bms-bpu-panel bms-bpu-discussion', 'id' => 'bms-bpu-discussion']);
    }
}
