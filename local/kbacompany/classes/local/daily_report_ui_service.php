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
            int $revision, string $requestkey): string {
        $out = html_writer::start_tag('form', ['method' => 'post', 'action' => self::url($uuid, ['day' => $day])->out(false),
            'class' => 'bms-dailyreport-form mb-4', 'data-dailyreport-form' => '1']);
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
        $out .= html_writer::div(
            html_writer::tag('button', s(self::str('save')), ['type' => 'submit', 'name' => 'action', 'value' => 'save',
                'class' => 'btn btn-secondary mr-2 mb-2', 'formnovalidate' => 'formnovalidate']) .
            html_writer::tag('button', s(self::str('submit')), ['type' => 'submit', 'name' => 'action', 'value' => 'submit',
                'class' => 'btn btn-primary mb-2', 'data-dailyreport-submit' => '1']),
            'bms-dailyreport-actions');
        $out .= html_writer::end_tag('form');
        return $out;
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
            bool $late, array $reviews = []): string {
        $meta = [self::str('version', (int)$version->versionno),
            self::str('submittedat', userdate((int)$version->submittedat))];
        if ($late) {
            $meta[] = self::str('late');
        }
        $meta[] = self::str('reviewer') . ': ' . ($reviewer !== null && $reviewer !== '' ? $reviewer : self::str('reviewer_none'));
        $out = html_writer::start_tag('article', ['class' => 'border rounded p-3 mb-3 bms-dailyreport-version',
            'data-dailyreport-version' => (int)$version->versionno]);
        $out .= html_writer::tag('p', s(implode(' · ', $meta)), ['class' => 'text-muted mb-2']);
        $out .= html_writer::tag('p', s(self::str('readonly_note')), ['class' => 'small text-muted']);
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
            $out .= html_writer::div(s(self::str('decision_' . $review->decision) . ($review->rating !== '' ?
                ' · ' . self::str('rating_' . $review->rating) : '') . ' · ' . userdate((int)$review->timecreated)) .
                (trim((string)$review->comment) !== '' ? html_writer::div(nl2br(s((string)$review->comment))) : ''),
                'border-top pt-2 mt-2 bms-dailyreport-decision');
        }
        $out .= html_writer::end_tag('article');
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
                self::status_badge((string)$report->status),
                $current ? (int)$current->versionno : '—',
                $current ? s(userdate((int)$current->submittedat)) . ($row['late'] ? ' · ' . s(self::str('late')) : '') : '—',
            ];
        }
        return html_writer::div(html_writer::table($table), 'table-responsive') .
            $OUTPUT->paging_bar($history['total'], $history['page'], daily_report_store_service::PAGE_SIZE,
                self::url($uuid, ['tab' => 'history']), 'page');
    }
}
