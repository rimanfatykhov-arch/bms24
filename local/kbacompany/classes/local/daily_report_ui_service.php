<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

use html_writer;
use moodle_url;

/**
 * ADR-0009 R1 presentation of saved daily reports. Presentation only: data comes from daily_report_store_service,
 * every user string is escaped, and the live day summary is always labelled apart from a saved version.
 */
final class daily_report_ui_service {
    private const ICONS = ['none' => '○', 'draft' => '✎', 'submitted' => '⏳', 'returned' => '↩', 'accepted' => '✓'];

    private static function str(string $key, $a = null): string {
        return get_string('dailyreports_' . $key, 'local_kbacompany', $a);
    }

    /** Status is always text plus icon, never colour alone. */
    public static function status_badge(string $status): string {
        $status = isset(self::ICONS[$status]) ? $status : 'none';
        return html_writer::span(html_writer::span(self::ICONS[$status], '', ['aria-hidden' => 'true']) . ' ' .
            s(self::str('status_' . $status)), 'badge badge-light border bms-dailyreport-status',
            ['data-dailyreport-status' => $status]);
    }

    /** ADR-0010: the self-review marker is always text, never colour alone. */
    public static function self_badge(): string {
        return html_writer::span(html_writer::span('↺', '', ['aria-hidden' => 'true']) . ' ' . s(self::str('selfreview_badge')),
            'badge badge-warning border bms-dailyreport-selfreview', ['data-dailyreport-selfreview' => '1']);
    }

    /** Human-readable reviewer source of a version (stored as data, see daily_report_reviewer_service::SOURCES). */
    public static function source_label(string $source): string {
        return in_array($source, daily_report_reviewer_service::SOURCES, true) && $source !== '' ?
            self::str('source_' . $source) : self::str('source_none');
    }

    private static function names(array $userids): array {
        $out = [];
        foreach ($userids as $userid) {
            $user = \core_user::get_user((int)$userid);
            if ($user) {
                $out[] = fullname($user);
            }
        }
        return $out;
    }

    public static function url(string $uuid, array $params = []): moodle_url {
        return new moodle_url('/local/kbacompany/daily_report.php', ['uuid' => $uuid] + $params);
    }

    private static function field_label(string $field): string {
        return self::str('field_' . $field);
    }

    /**
     * Draft form. $values/$links are the text to show (saved draft or the posted text after an error);
     * $candidates are tasks the author can link (day data plus already linked ones).
     */
    public static function form(string $uuid, string $day, array $values, array $links, array $candidates,
            int $revision, string $requestkey, array $review = []): string {
        $out = html_writer::start_tag('form', ['method' => 'post', 'action' => self::url($uuid, ['day' => $day])->out(false),
            'class' => 'bms-dailyreport-form mb-4', 'data-dailyreport-form' => '1', 'id' => 'dailyreport-form']);
        foreach (['uuid' => $uuid, 'day' => $day, 'sesskey' => sesskey(), 'revision' => (string)$revision,
                'requestkey' => $requestkey] as $name => $value) {
            $out .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]);
        }
        foreach (daily_report_store_service::FIELDS as $field) {
            $id = 'dailyreport-' . $field;
            $required = $field === 'done';
            $out .= html_writer::start_div('form-group mb-3');
            $out .= html_writer::tag('label', s(self::field_label($field)) . ($required ?
                ' ' . html_writer::span('*', 'text-danger', ['aria-hidden' => 'true']) : ''), ['for' => $id]);
            $out .= html_writer::tag('textarea', s((string)($values[$field] ?? '')), ['id' => $id, 'name' => $field,
                'rows' => $field === 'done' ? 5 : 3, 'class' => 'form-control', 'maxlength' => daily_report_store_service::MAX_TEXT,
                'required' => $required ? 'required' : null, 'aria-required' => $required ? 'true' : null]);
            $out .= html_writer::end_div();
        }
        $out .= html_writer::start_tag('fieldset', ['class' => 'mb-3']);
        $out .= html_writer::tag('legend', s(self::str('linked')), ['class' => 'h6']);
        $out .= html_writer::tag('p', s(self::str('linked_note')), ['class' => 'text-muted small']);
        if (!$candidates) {
            $out .= html_writer::tag('p', s(self::str('linked_none')));
        }
        foreach ($candidates as $taskuuid => $title) {
            $id = 'dailyreport-task-' . $taskuuid;
            $out .= html_writer::div(html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => 'linkedtasks[]',
                    'value' => $taskuuid, 'id' => $id, 'class' => 'form-check-input',
                    'checked' => in_array($taskuuid, $links, true) ? 'checked' : null]) .
                html_writer::tag('label', s($title), ['for' => $id, 'class' => 'form-check-label']), 'form-check');
        }
        $out .= html_writer::end_tag('fieldset');
        if ($review) {
            $out .= self::reviewer_preview($review);
            $out .= self::copy_picker($review['options'] ?? [], $review['selected'] ?? []);
        }
        $out .= html_writer::div(
            html_writer::tag('button', s(self::str('save')), ['type' => 'submit', 'name' => 'action', 'value' => 'save',
                'class' => 'btn btn-secondary mr-2 mb-2', 'formnovalidate' => 'formnovalidate']) .
            html_writer::tag('button', s(self::str('submit')), ['type' => 'submit', 'name' => 'action', 'value' => 'submit',
                'class' => 'btn btn-primary mb-2', 'data-dailyreport-submit' => '1']),
            'bms-dailyreport-actions');
        $out .= html_writer::end_tag('form');
        return $out;
    }

    /**
     * ADR-0010 D7: the author sees, before sending, who will review the report and who gets an automatic copy.
     * $review = ['reviewerid' => int, 'source' => string, 'cc' => int[]] as computed by the store preview.
     */
    public static function reviewer_preview(array $review): string {
        $source = (string)($review['source'] ?? 'none');
        $reviewerid = (int)($review['reviewerid'] ?? 0);
        if ($source === 'self_top') {
            $text = html_writer::tag('p', self::self_badge() . ' ' . s(self::str('reviewer_preview_self')), ['class' => 'mb-1']);
        } else if ($reviewerid > 0) {
            $text = html_writer::tag('p', s(self::str('reviewer_preview') . ': ' . implode('', self::names([$reviewerid])) .
                ' (' . self::source_label($source) . ')'), ['class' => 'mb-1']);
        } else {
            $text = html_writer::tag('p', s(self::str('reviewer_preview_none')), ['class' => 'mb-1']);
        }
        if (!empty($review['cc'])) {
            $text .= html_writer::tag('p', s(self::str('cc_auto') . ': ' . implode(', ', self::names($review['cc']))),
                ['class' => 'mb-1']);
        }
        $text .= html_writer::tag('p', s(self::str('reviewer_preview_note')), ['class' => 'small text-muted mb-0']);
        return html_writer::div($text, 'alert alert-secondary bms-dailyreport-reviewer',
            ['data-dailyreport-reviewer' => $source, 'data-dailyreport-reviewerid' => (string)$reviewerid]);
    }

    /** Manual copy recipients (other active members of the company); read-only for them, never a decision. */
    private static function copy_picker(array $options, array $selected): string {
        if (!$options) {
            return '';
        }
        $out = html_writer::start_tag('fieldset', ['class' => 'mb-3']);
        $out .= html_writer::tag('legend', s(self::str('cc')), ['class' => 'h6']);
        $out .= html_writer::tag('p', s(self::str('cc_note')), ['class' => 'text-muted small', 'id' => 'dailyreport-cc-note']);
        $out .= html_writer::label(s(self::str('cc')), 'dailyreport-cc', false, ['class' => 'sr-only']);
        $out .= html_writer::select($options, 'ccusers[]', array_map('intval', $selected), false,
            ['id' => 'dailyreport-cc', 'multiple' => 'multiple', 'size' => min(6, max(3, count($options))),
             'class' => 'custom-select', 'aria-describedby' => 'dailyreport-cc-note']);
        return $out . html_writer::end_tag('fieldset');
    }

    /** Side-by-side texts after a revision conflict: nothing is lost, the author decides what to keep. */
    public static function conflict(\stdClass $saved, array $mine): string {
        $rows = '';
        foreach (daily_report_store_service::FIELDS as $field) {
            if ((string)$saved->$field === (string)($mine[$field] ?? '')) {
                continue;
            }
            $rows .= html_writer::tag('dt', s(self::field_label($field)));
            $rows .= html_writer::tag('dd', html_writer::tag('strong', s(self::str('conflict_saved')) . ': ') .
                nl2br(s((string)$saved->$field)));
        }
        return html_writer::div(html_writer::tag('p', s(self::str('conflict_help'))) .
            ($rows !== '' ? html_writer::tag('dl', $rows) : ''), 'alert alert-warning', ['role' => 'alert',
                'data-dailyreport-conflict' => '1']);
    }

    /** One frozen version: employee text, linked tasks as they were at submission, live link only if allowed now. */
    public static function version(string $uuid, int $actor, \stdClass $version, array $links, ?string $reviewer,
            bool $late, array $reviews = [], array $copies = []): string {
        $meta = [self::str('version', (int)$version->versionno),
            self::str('submittedat', userdate((int)$version->submittedat))];
        if ($late) {
            $meta[] = self::str('late');
        }
        $selfreview = (string)$version->reviewersource === 'self_top';
        $meta[] = self::str('reviewer') . ': ' . ($reviewer !== null && $reviewer !== '' ? $reviewer : self::str('reviewer_none')) .
            ((string)$version->reviewersource !== '' ? ' (' . self::source_label((string)$version->reviewersource) . ')' : '');
        if ($copies) {
            $meta[] = self::str('cc_list') . ': ' . implode(', ', self::names(array_map(static function($copy): int {
                return (int)$copy->userid;
            }, $copies)));
        }
        $out = html_writer::start_tag('article', ['class' => 'border rounded p-3 mb-3 bms-dailyreport-version',
            'data-dailyreport-version' => (int)$version->versionno]);
        $out .= html_writer::tag('p', s(implode(' · ', $meta)), ['class' => 'text-muted mb-2']);
        if ($selfreview) {
            $out .= html_writer::div(self::self_badge() . ' ' . s(self::str('selfreview_note')), 'alert alert-warning py-2');
        }
        $out .= html_writer::tag('p', s(self::str('readonly_note')), ['class' => 'small text-muted']);
        if (trim((string)($version->correctionnote ?? '')) !== '') {
            $out .= html_writer::div(html_writer::tag('strong', s(self::str('correction_note')) . ': ') .
                nl2br(s((string)$version->correctionnote)), 'alert alert-info');
        }
        $out .= html_writer::start_tag('dl');
        foreach (daily_report_store_service::FIELDS as $field) {
            $text = trim((string)$version->$field);
            if ($text === '') {
                continue;
            }
            $out .= html_writer::tag('dt', s(self::field_label($field))) . html_writer::tag('dd', nl2br(s($text)));
        }
        $out .= html_writer::end_tag('dl');
        $out .= html_writer::tag('h4', s(self::str('linked')), ['class' => 'h6']);
        if (!$links) {
            $out .= html_writer::tag('p', s(self::str('linked_none')));
        } else {
            $items = '';
            foreach ($links as $link) {
                $statuskey = 'taskcenter_status_' . $link->status;
                $statustext = get_string_manager()->string_exists($statuskey, 'local_kbacompany') ?
                    get_string($statuskey, 'local_kbacompany') : (string)$link->status;
                $line = s($link->title) . ' — ' . s($statustext) .
                    ($link->duedate !== '' ? ' · ' . s($link->duedate) : '') . ' ' .
                    html_writer::span(s(self::str('snapshot_note')), 'small text-muted');
                if (task_service::readable_projection($uuid, $actor, (string)$link->taskuuid) !== null) {
                    $line .= ' ' . html_writer::link(new moodle_url('/local/kbacompany/tasks.php',
                        ['uuid' => $uuid, 'task' => $link->taskuuid]), s(self::str('opentask')));
                }
                $items .= html_writer::tag('li', $line);
            }
            $out .= html_writer::tag('ul', $items);
        }
        foreach ($reviews as $review) {
            $selfdecision = (int)$review->reviewerid > 0 && (int)$review->reviewerid === (int)$version->createdby &&
                $selfreview;
            $out .= html_writer::div(($selfdecision ? self::self_badge() . ' ' : '') .
                s(self::str('decision_' . $review->decision) . ($review->rating !== '' ?
                ' · ' . self::str('rating_' . $review->rating) : '') . ' · ' . userdate((int)$review->timecreated)) .
                (trim((string)$review->comment) !== '' ? html_writer::div(nl2br(s((string)$review->comment))) : ''),
                'border-top pt-2 mt-2 bms-dailyreport-decision');
        }
        $out .= html_writer::end_tag('article');
        return $out;
    }

    private static function post_form(string $uuid, array $params, array $hidden, string $body, string $class = ''): string {
        $out = html_writer::start_tag('form', ['method' => 'post', 'action' => self::url($uuid, $params)->out(false),
            'class' => 'mb-3 ' . $class]);
        foreach (['uuid' => $uuid, 'sesskey' => sesskey(), 'requestkey' => \core\uuid::generate()] + $hidden as $name => $value) {
            $out .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => (string)$value]);
        }
        return $out . $body . html_writer::end_tag('form');
    }

    private static function textarea(string $name, string $id, string $label, bool $required): string {
        return html_writer::div(html_writer::tag('label', s($label) . ($required ?
                ' ' . html_writer::span('*', 'text-danger', ['aria-hidden' => 'true']) : ''), ['for' => $id]) .
            html_writer::tag('textarea', '', ['name' => $name, 'id' => $id, 'rows' => 3, 'class' => 'form-control',
                'maxlength' => daily_report_store_service::MAX_TEXT, 'required' => $required ? 'required' : null,
                'aria-required' => $required ? 'true' : null]), 'form-group mb-2');
    }

    private static function ratings(string $prefix): string {
        $out = html_writer::start_tag('fieldset', ['class' => 'mb-2']);
        $out .= html_writer::tag('legend', s(self::str('rating')), ['class' => 'h6']);
        foreach (daily_report_review_service::RATINGS as $rating) {
            $id = $prefix . '-' . $rating;
            $out .= html_writer::div(html_writer::empty_tag('input', ['type' => 'radio', 'name' => 'rating', 'value' => $rating,
                    'id' => $id, 'class' => 'form-check-input', 'required' => 'required']) .
                html_writer::tag('label', s(self::str('rating_' . $rating)), ['for' => $id, 'class' => 'form-check-label']),
                'form-check');
        }
        return $out . html_writer::end_tag('fieldset');
    }

    /** Author controls after a decision: rework a returned version, or correct an accepted one with an explanation. */
    public static function revision_controls(string $uuid, string $day, string $status, string $selfreviewreport = ''): string {
        if ($status === 'submitted' && $selfreviewreport !== '') {
            // ADR-0010 D3: the author at the top reviews own report in the review card.
            return html_writer::link(self::url($uuid, ['tab' => 'review', 'report' => $selfreviewreport]),
                s(self::str('selfreview_open')), ['class' => 'btn btn-primary mb-3']);
        }
        if ($status === 'returned') {
            return self::post_form($uuid, ['day' => $day], ['day' => $day, 'action' => 'rework'],
                html_writer::tag('button', s(self::str('rework')), ['type' => 'submit', 'class' => 'btn btn-primary']));
        }
        if ($status === 'accepted') {
            return self::post_form($uuid, ['day' => $day], ['day' => $day, 'action' => 'correct'],
                self::textarea('correctionnote', 'dailyreport-correction', self::str('correction_note'), true) .
                html_writer::tag('button', s(self::str('correct')), ['type' => 'submit', 'class' => 'btn btn-secondary']));
        }
        return '';
    }

    /** Reviewer queue with sub-filters; $queues come from daily_report_review_service::queues() for this actor. */
    public static function queue(string $uuid, array $queue, array $queues): string {
        global $OUTPUT;
        $links = '';
        foreach ($queues as $name) {
            $current = $name === $queue['queue'];
            $links .= html_writer::link(self::url($uuid, ['tab' => 'review', 'queue' => $name]), s(self::str('queue_' . $name)),
                ['class' => 'btn btn-sm mr-2 mb-2 ' . ($current ? 'btn-primary' : 'btn-outline-secondary'),
                 'aria-current' => $current ? 'page' : null]);
        }
        $out = html_writer::tag('nav', $links, ['aria-label' => self::str('queue_filters'), 'class' => 'mb-3']);
        if ($queue['queue'] === 'settings') {
            return $out;
        }
        if (!$queue['items']) {
            return $out . html_writer::tag('p', s(self::str('queue_empty')));
        }
        $table = new \html_table();
        $table->attributes['class'] = 'generaltable bms-dailyreport-queue';
        $table->head = [self::str('history_date'), self::str('employee'), self::str('history_version'),
            self::str('history_submitted'), self::str('history_status')];
        foreach ($queue['items'] as $row) {
            $user = \core_user::get_user((int)$row->userid);
            $table->data[] = [
                html_writer::link(self::url($uuid, ['tab' => 'review', 'report' => $row->uuid]), s($row->reportday)),
                s($user ? fullname($user) : '—'), (int)$row->versionno, s(userdate((int)$row->submittedat)),
                self::status_badge((string)$row->status) . ((string)($row->reviewersource ?? '') === 'self_top' ?
                    ' ' . self::self_badge() : '')];
        }
        return $out . html_writer::div(html_writer::table($table), 'table-responsive') .
            $OUTPUT->paging_bar($queue['total'], $queue['page'], daily_report_review_service::PAGE_SIZE,
                self::url($uuid, ['tab' => 'review', 'queue' => $queue['queue']]), 'page');
    }

    /** Report card for the reviewer or the read-only administrator, with decision forms only when allowed. */
    public static function card(string $uuid, int $actor, array $card, array $candidates): string {
        global $OUTPUT;
        $report = $card['report'];
        $out = $OUTPUT->heading(s(($card['author'] ? fullname($card['author']) : '—') . ' · ' . $report->reportday) . ' ' .
            self::status_badge((string)$report->status), 3);
        if ($card['selfreview'] ?? false) {
            $out .= html_writer::div(self::self_badge() . ' ' . s(self::str('selfreview_note')), 'alert alert-warning',
                ['role' => 'note']);
        }
        if (!$card['can_decide'] && !$card['can_change']) {
            $readonly = $card['admin'] ? 'admin_readonly' : (($card['manager'] ?? false) ? 'manager_readonly' :
                (($card['copy'] ?? false) ? 'copy_readonly' : ''));
            if ($readonly !== '') {
                $out .= html_writer::tag('p', s(self::str($readonly)), ['class' => 'text-muted',
                    'data-dailyreport-readonly' => $readonly]);
            }
        }
        $current = $card['versions'][0] ?? null;
        $hidden = ['tab' => 'review', 'report' => $report->uuid, 'version' => $current ? $current->uuid : ''];
        $params = ['tab' => 'review', 'report' => $report->uuid];
        if ($card['can_decide']) {
            $out .= html_writer::div(
                self::post_form($uuid, $params, $hidden + ['action' => 'accept'],
                    html_writer::tag('h4', s(self::str('accept_heading')), ['class' => 'h6']) . self::ratings('dailyreport-rating') .
                    self::textarea('comment', 'dailyreport-accept-comment', self::str('comment_optional'), false) .
                    html_writer::tag('button', s(self::str('accept')), ['type' => 'submit', 'class' => 'btn btn-primary']),
                    'border rounded p-3') .
                self::post_form($uuid, $params, $hidden + ['action' => 'return'],
                    html_writer::tag('h4', s(self::str('return_heading')), ['class' => 'h6']) .
                    self::textarea('comment', 'dailyreport-return-reason', self::str('return_reason'), true) .
                    html_writer::tag('button', s(self::str('return')), ['type' => 'submit', 'class' => 'btn btn-outline-danger']),
                    'border rounded p-3'),
                'bms-dailyreport-decide');
        }
        if ($card['can_change']) {
            $out .= self::post_form($uuid, $params, $hidden + ['action' => 'rerate'],
                html_writer::tag('h4', s(self::str('rerate_heading')), ['class' => 'h6']) . self::ratings('dailyreport-rerate') .
                self::textarea('comment', 'dailyreport-rerate-reason', self::str('change_reason'), true) .
                html_writer::tag('button', s(self::str('rerate')), ['type' => 'submit', 'class' => 'btn btn-secondary']),
                'border rounded p-3') .
                self::post_form($uuid, $params, $hidden + ['action' => 'return'],
                    html_writer::tag('h4', s(self::str('revoke_heading')), ['class' => 'h6']) .
                    self::textarea('comment', 'dailyreport-revoke-reason', self::str('return_reason'), true) .
                    html_writer::tag('button', s(self::str('return')), ['type' => 'submit', 'class' => 'btn btn-outline-danger']),
                    'border rounded p-3');
        }
        if ($card['can_reassign'] && $candidates) {
            $select = html_writer::label(s(self::str('reassign_to')), 'dailyreport-reassign') .
                html_writer::select($candidates, 'reviewerid', '', ['' => self::str('choose')],
                    ['id' => 'dailyreport-reassign', 'class' => 'custom-select mr-2', 'required' => 'required']);
            $out .= self::post_form($uuid, $params, ['tab' => 'review', 'report' => $report->uuid, 'action' => 'reassign'],
                $select . html_writer::tag('button', s(self::str('reassign')), ['type' => 'submit', 'class' => 'btn btn-secondary']),
                'border rounded p-3');
        }
        foreach ($card['versions'] as $i => $version) {
            $reviewer = (int)$version->reviewerid > 0 ? \core_user::get_user((int)$version->reviewerid) : false;
            if ($i === 1) {
                $out .= $OUTPUT->heading(s(self::str('versions_previous')), 4);
            }
            $out .= self::version($uuid, $actor, $version, $card['links'][$version->uuid] ?? [],
                $reviewer ? fullname($reviewer) : null, false, $card['reviews'][$version->uuid] ?? [],
                $card['copies'][$version->uuid] ?? []);
        }
        return $out;
    }

    /** Own reports by date with status, version and submission time; links open the day. */
    public static function history(string $uuid, array $history): string {
        global $OUTPUT;
        if (!$history['items']) {
            return html_writer::tag('p', s(self::str('history_empty')));
        }
        $table = new \html_table();
        $table->attributes['class'] = 'generaltable bms-dailyreport-history';
        $table->head = [self::str('history_date'), self::str('history_status'), self::str('history_version'),
            self::str('history_submitted')];
        foreach ($history['items'] as $row) {
            $report = $row['report']; $current = $row['current'];
            $table->data[] = [
                html_writer::link(self::url($uuid, ['day' => $report->reportday]), s($report->reportday)),
                self::status_badge((string)$report->status) . ($current && (string)$current->reviewersource === 'self_top' ?
                    ' ' . self::self_badge() : ''),
                $current ? (int)$current->versionno : '—',
                $current ? s(userdate((int)$current->submittedat)) . ($row['late'] ? ' · ' . s(self::str('late')) : '') : '—',
            ];
        }
        return html_writer::div(html_writer::table($table), 'table-responsive') .
            $OUTPUT->paging_bar($history['total'], $history['page'], daily_report_store_service::PAGE_SIZE,
                self::url($uuid, ['tab' => 'history']), 'page');
    }

    /** ADR-0010 D3/D5: company review rules, edited by a company administrator only. */
    public static function settings_form(string $uuid, \stdClass $settings, array $people): string {
        $radio = static function(string $value, string $label) use ($settings): string {
            $id = 'dailyreport-top-' . $value;
            return html_writer::div(html_writer::empty_tag('input', ['type' => 'radio', 'name' => 'toppolicy',
                    'value' => $value, 'id' => $id, 'class' => 'form-check-input',
                    'checked' => (string)$settings->toppolicy === $value ? 'checked' : null]) .
                html_writer::tag('label', s($label), ['for' => $id, 'class' => 'form-check-label']), 'form-check');
        };
        $body = html_writer::tag('h3', s(self::str('settings_heading')), ['class' => 'h5']);
        $body .= html_writer::start_tag('fieldset', ['class' => 'mb-3']);
        $body .= html_writer::tag('legend', s(self::str('settings_top')), ['class' => 'h6']);
        $body .= $radio('self', self::str('settings_top_self')) . $radio('reviewer', self::str('settings_top_reviewer'));
        $body .= html_writer::label(s(self::str('settings_topreviewer')), 'dailyreport-topreviewer', false, ['class' => 'mt-2']);
        $body .= html_writer::select($people, 'topreviewerid', (int)$settings->topreviewerid, ['0' => self::str('choose')],
            ['id' => 'dailyreport-topreviewer', 'class' => 'custom-select']);
        $body .= html_writer::end_tag('fieldset');
        $body .= html_writer::label(s(self::str('settings_cc')), 'dailyreport-ccpolicy');
        $body .= html_writer::select(['none' => self::str('settings_cc_none'), 'upper' => self::str('settings_cc_upper')],
            'ccpolicy', (string)$settings->ccpolicy, false, ['id' => 'dailyreport-ccpolicy', 'class' => 'custom-select mb-3']);
        $body .= html_writer::div(html_writer::tag('button', s(self::str('settings_save')),
            ['type' => 'submit', 'class' => 'btn btn-secondary']));
        return self::post_form($uuid, ['tab' => 'review', 'queue' => 'settings'], ['tab' => 'review', 'action' => 'settings',
            'revision' => (int)$settings->revision], $body, 'border rounded p-3 bms-dailyreport-settings');
    }
}
