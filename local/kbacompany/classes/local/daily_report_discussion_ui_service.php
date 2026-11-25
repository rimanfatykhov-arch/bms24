<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * R3: render-only "Discussion" section of a daily report (review card and the author's day view). Data and ACL come from
 * daily_report_discussion_service; files, editing and withdrawing own messages stay in the Communication Center (same
 * canonical thread). Messages are plain text, escaped on output.
 */
final class daily_report_discussion_ui_service {
    private static function tr(string $key): string {
        return get_string($key, 'local_kbacompany');
    }

    private static function note(string $text, array $attrs = []): string {
        return \html_writer::tag('p', s($text), ['class' => 'text-muted small'] + $attrs);
    }

    private static function quote(?array $quote): string {
        if (!$quote) { return ''; }
        $text = $quote['status'] === 'active' ? (string)$quote['excerpt'] : self::tr('communication_messagewithdrawn');
        return \html_writer::tag('blockquote', \html_writer::tag('strong', s((string)$quote['author_name'])) .
            \html_writer::div(s($text)), ['class' => 'bms-tui-message-quote']);
    }

    private static function message(string $company, array $report, array $msg, bool $canpost): string {
        $active = $msg['status'] === 'active';
        $text = $active ? (trim((string)$msg['body']) !== '' ?
            \html_writer::div(format_text($msg['body'], FORMAT_PLAIN), 'bms-tui-prose') : '') :
            self::note(self::tr('communication_messagewithdrawn'));
        if ($active && !empty($msg['edited'])) { $text .= self::note(self::tr('communication_edited')); }
        if ($active && !empty($msg['attachments'])) {
            $text .= self::note(self::tr('dailyreports_discussion_files'));
        }
        $actions = '';
        if ($active && $canpost) {
            $actions = \html_writer::div(\html_writer::link(daily_report_discussion_service::url($company, $report,
                ['reply' => $msg['uuid']]), s(self::tr('communication_reply'))), 'bms-tui-message-actions');
        }
        return \html_writer::tag('article',
            \html_writer::tag('strong', s($msg['author_name'])) . self::note(userdate((int)$msg['time_created'])) .
            self::quote($msg['reply_to'] ?? null) . $text . $actions,
            ['class' => 'bms-tui-message border-bottom py-2', 'id' => 'dailyreport-message-' . $msg['uuid'],
                'data-dailyreport-discussion-message' => $msg['uuid']]);
    }

    /** The whole section. $reply is a presentation-only target; the service re-validates it on POST. */
    public static function render(string $company, array $d, string $reply = '', string $error = ''): string {
        $report = $d['report'];
        $canpost = !empty($d['can_post']);
        $html = $error !== '' ? \html_writer::div(s($error), 'alert alert-danger', ['role' => 'alert']) : '';
        $list = '';
        foreach ($d['messages'] as $msg) { $list .= self::message($company, $report, $msg, $canpost); }
        $html .= $list !== '' ? \html_writer::div($list, 'bms-dailyreport-discussion-messages') :
            self::note(self::tr('dailyreports_discussion_empty'), ['data-dailyreport-discussion-empty' => '1']);
        if (!$canpost) {
            $html .= self::note(self::tr('dailyreports_discussion_readonly'), ['data-dailyreport-discussion-readonly' => '1']);
        } else {
            $hidden = ['uuid' => $company, 'action' => 'discuss', 'discussreport' => $report['uuid'], 'sesskey' => sesskey()];
            $target = '';
            foreach ($d['messages'] as $msg) {
                if ($reply !== '' && $msg['uuid'] === $reply && $msg['status'] === 'active') {
                    $hidden['replytouuid'] = $msg['uuid'];
                    $target = \html_writer::div(s(self::tr('communication_replyingto')) . self::quote([
                            'author_name' => $msg['author_name'], 'status' => 'active',
                            'excerpt' => \core_text::substr(preg_replace('/\s+/u', ' ', trim((string)$msg['body'])), 0, 160)]) .
                        \html_writer::link(daily_report_discussion_service::url($company, $report),
                            s(self::tr('communication_cancelreply'))), 'bms-tui-reply-target');
                    break;
                }
            }
            $fields = '';
            foreach ($hidden as $name => $value) {
                $fields .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]);
            }
            $fields .= $target;
            $fields .= \html_writer::label(s(self::tr('communication_message')), 'dailyreport_discussion_message');
            $fields .= \html_writer::tag('textarea', '', ['id' => 'dailyreport_discussion_message', 'name' => 'message',
                'rows' => 3, 'maxlength' => 20000, 'required' => 'required', 'class' => 'form-control mb-2']);
            $fields .= \html_writer::tag('button', s(self::tr('communication_send')),
                ['type' => 'submit', 'class' => 'btn btn-primary']);
            $html .= \html_writer::tag('form', $fields, ['method' => 'post',
                'action' => (new \moodle_url('/local/kbacompany/daily_report.php'))->out(false),
                'class' => 'bms-dailyreport-discussion-compose', 'data-dailyreport-discussion-composer' => '1']);
        }
        $centerparams = ['uuid' => $company, 'tab' => 'discussions'];
        if ($d['thread_uuid'] !== '') {
            $centerparams['thread'] = $d['thread_uuid'];
            $html .= \html_writer::div(\html_writer::link(new \moodle_url('/local/kbacompany/communication_center.php',
                $centerparams), s(self::tr('taskcenter_discussion_open'))), 'mt-2');
        }
        return \html_writer::tag('section', \html_writer::tag('h3', s(self::tr('dailyreports_discussion_heading'))) .
            self::note(self::tr('dailyreports_discussion_note')) . $html,
            ['class' => 'bms-dailyreport-discussion mt-4', 'id' => 'dailyreport-discussion',
                'data-dailyreport-discussion' => $report['uuid'], 'data-dailyreport-discussion-canpost' => $canpost ? '1' : '0']);
    }
}
