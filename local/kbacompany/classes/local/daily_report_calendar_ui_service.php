<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

use html_writer;

/**
 * R5a: render-only screens of the reporting calendar (administrator) and of absence periods (administrator and nearest
 * manager). Data and ACL come from daily_report_calendar_service; every form is POST + sesskey and re-checked on the server.
 */
final class daily_report_calendar_ui_service {
    private static function str(string $key, $a = null): string {
        return get_string('dailyreports_' . $key, 'local_kbacompany', $a);
    }

    private static function form(string $uuid, string $queue, array $hidden, string $body, array $attrs = []): string {
        $out = html_writer::start_tag('form', ['method' => 'post', 'class' => 'mb-3',
            'action' => daily_report_ui_service::url($uuid, ['tab' => 'review', 'queue' => $queue])->out(false)] + $attrs);
        foreach (['uuid' => $uuid, 'sesskey' => sesskey(), 'tab' => 'review', 'queue' => $queue] + $hidden as $name => $value) {
            $out .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => (string)$value]);
        }
        return $out . $body . html_writer::end_tag('form');
    }

    private static function field(string $id, string $label, string $control): string {
        return html_writer::div(html_writer::tag('label', s($label), ['for' => $id, 'class' => 'd-block']) . $control,
            'form-group mr-3 mb-2');
    }

    private static function date(string $name, string $id, string $value = ''): string {
        return html_writer::empty_tag('input', ['type' => 'date', 'name' => $name, 'id' => $id, 'value' => $value,
            'required' => 'required', 'class' => 'form-control']);
    }

    private static function absence_kinds(): array {
        $out = [];
        foreach (daily_report_calendar_service::ABSENCE_KINDS as $kind) { $out[$kind] = self::str('absence_kind_' . $kind); }
        return $out;
    }

    /** Administrator screen: switch, working weekdays, deadline time and calendar exceptions. */
    public static function calendar(string $uuid, \stdClass $cal, array $offdays, array $queues): string {
        $out = daily_report_ui_service::queue($uuid, ['queue' => 'calendar', 'items' => [], 'total' => 0, 'page' => 0,
            'nolist' => true], $queues);
        $out .= html_writer::tag('p', s(self::str('calendar_note')), ['class' => 'text-muted']);
        $state = !empty($cal->enabled) ? self::str('calendar_on_since', (string)$cal->enabledfrom) : self::str('calendar_off');
        $out .= html_writer::tag('p', s($state), ['data-dailyreport-calendar-state' => !empty($cal->enabled) ? 'on' : 'off']);

        $body = html_writer::div(html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => 'enabled', 'value' => 1,
                'id' => 'dailyreport-calendar-enabled', 'class' => 'form-check-input',
                'checked' => !empty($cal->enabled) ? 'checked' : null]) .
            html_writer::tag('label', s(self::str('calendar_enabled')), ['for' => 'dailyreport-calendar-enabled',
                'class' => 'form-check-label']), 'form-check mb-3');
        $days = '';
        for ($i = 1; $i <= 7; $i++) {
            $id = 'dailyreport-weekday-' . $i;
            $days .= html_writer::div(html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => 'weekday[]',
                    'value' => $i, 'id' => $id, 'class' => 'form-check-input',
                    'checked' => substr((string)$cal->workdays, $i - 1, 1) === '1' ? 'checked' : null]) .
                html_writer::tag('label', s(self::str('weekday_' . $i)), ['for' => $id, 'class' => 'form-check-label']),
                'form-check form-check-inline');
        }
        $body .= html_writer::tag('fieldset', html_writer::tag('legend', s(self::str('calendar_workdays')), ['class' => 'h6']) .
            $days, ['class' => 'mb-3']);
        $minutes = (int)$cal->deadlineminutes;
        $body .= self::field('dailyreport-deadline', self::str('calendar_deadline'),
            html_writer::empty_tag('input', ['type' => 'time', 'name' => 'deadline', 'id' => 'dailyreport-deadline',
                'required' => 'required', 'class' => 'form-control',
                'value' => sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60)]));
        $body .= html_writer::tag('button', s(self::str('settings_save')), ['type' => 'submit', 'class' => 'btn btn-secondary']);
        $out .= self::form($uuid, 'calendar', ['action' => 'calendar', 'revision' => (int)$cal->revision],
            html_writer::tag('h3', s(self::str('calendar_heading')), ['class' => 'h5']) . $body,
            ['data-dailyreport-calendar-form' => '1', 'class' => 'mb-3 border rounded p-3']);

        $out .= html_writer::tag('h3', s(self::str('offday_heading')), ['class' => 'h5']);
        $out .= html_writer::tag('p', s(self::str('offday_note')), ['class' => 'text-muted']);
        $add = html_writer::div(
            self::field('dailyreport-offday-day', self::str('offday_day'), self::date('offday', 'dailyreport-offday-day')) .
            self::field('dailyreport-offday-kind', self::str('offday_kind'), html_writer::select(
                ['holiday' => self::str('offday_kind_holiday'), 'workday' => self::str('offday_kind_workday')], 'kind',
                'holiday', false, ['id' => 'dailyreport-offday-kind', 'class' => 'custom-select'])) .
            self::field('dailyreport-offday-name', self::str('offday_name'), html_writer::empty_tag('input', ['type' => 'text',
                'name' => 'name', 'id' => 'dailyreport-offday-name', 'maxlength' => 255, 'class' => 'form-control'])) .
            html_writer::div(html_writer::tag('button', s(self::str('offday_add')), ['type' => 'submit',
                'class' => 'btn btn-outline-primary']), 'align-self-end mb-2'), 'd-flex flex-wrap');
        $out .= self::form($uuid, 'calendar', ['action' => 'offdayadd'], $add, ['data-dailyreport-offday-form' => '1']);
        if (!$offdays) {
            return $out . html_writer::tag('p', s(self::str('offday_empty')));
        }
        $table = new \html_table();
        $table->attributes['class'] = 'generaltable bms-dailyreport-offdays';
        $table->head = [self::str('offday_day'), self::str('offday_kind'), self::str('offday_name'), ''];
        foreach ($offdays as $row) {
            $remove = self::form($uuid, 'calendar', ['action' => 'offdayremove', 'offday' => (string)$row->uuid],
                html_writer::tag('button', s(self::str('offday_remove')), ['type' => 'submit',
                    'class' => 'btn btn-sm btn-outline-danger',
                    'aria-label' => self::str('offday_remove_for', (string)$row->offday)]), ['class' => 'mb-0']);
            $table->data[] = [s((string)$row->offday), s(self::str('offday_kind_' . $row->kind)), s((string)$row->name),
                $remove];
        }
        return $out . html_writer::div(html_writer::table($table), 'table-responsive', ['data-dailyreport-offdays' => '1']);
    }

    /** Absence periods of the people the actor manages: add, change dates/kind, cancel. */
    public static function absences(string $uuid, array $data, array $queues): string {
        global $OUTPUT;
        $out = daily_report_ui_service::queue($uuid, ['queue' => 'absences', 'items' => [], 'total' => 0, 'page' => 0,
            'nolist' => true], $queues);
        $out .= html_writer::tag('p', s(self::str('absence_note')), ['class' => 'text-muted']);
        if (!$data['people']) {
            return $out . html_writer::tag('p', s(self::str('absence_nobody')));
        }
        $kinds = self::absence_kinds();
        $add = html_writer::div(
            self::field('dailyreport-absence-user', self::str('employee'), html_writer::select($data['people'], 'employee', '',
                ['' => self::str('choose')], ['id' => 'dailyreport-absence-user', 'class' => 'custom-select',
                    'required' => 'required'])) .
            self::field('dailyreport-absence-kind', self::str('absence_kind'), html_writer::select($kinds, 'kind', 'vacation',
                false, ['id' => 'dailyreport-absence-kind', 'class' => 'custom-select'])) .
            self::field('dailyreport-absence-start', self::str('absence_start'), self::date('startday', 'dailyreport-absence-start')) .
            self::field('dailyreport-absence-end', self::str('absence_end'), self::date('endday', 'dailyreport-absence-end')) .
            html_writer::div(html_writer::tag('button', s(self::str('absence_add')), ['type' => 'submit',
                'class' => 'btn btn-outline-primary']), 'align-self-end mb-2'), 'd-flex flex-wrap');
        $out .= self::form($uuid, 'absences', ['action' => 'absenceadd'],
            html_writer::tag('h3', s(self::str('absence_heading')), ['class' => 'h5']) . $add,
            ['data-dailyreport-absence-form' => '1', 'class' => 'mb-3 border rounded p-3']);
        if (!$data['items']) {
            return $out . html_writer::tag('p', s(self::str('absence_empty')));
        }
        $list = '';
        foreach ($data['items'] as $row) {
            $name = $data['people'][(int)$row->userid] ?? '—';
            $id = 'dailyreport-absence-' . $row->uuid;
            $edit = html_writer::div(
                self::field($id . '-kind', self::str('absence_kind'), html_writer::select($kinds, 'kind', (string)$row->kind,
                    false, ['id' => $id . '-kind', 'class' => 'custom-select'])) .
                self::field($id . '-start', self::str('absence_start'), self::date('startday', $id . '-start',
                    (string)$row->startday)) .
                self::field($id . '-end', self::str('absence_end'), self::date('endday', $id . '-end', (string)$row->endday)) .
                html_writer::div(html_writer::tag('button', s(self::str('absence_save')), ['type' => 'submit',
                    'name' => 'action', 'value' => 'absencechange', 'class' => 'btn btn-sm btn-secondary mr-2']) .
                    html_writer::tag('button', s(self::str('absence_cancel')), ['type' => 'submit', 'name' => 'action',
                        'value' => 'absencecancel', 'formnovalidate' => 'formnovalidate',
                        'class' => 'btn btn-sm btn-outline-danger']), 'align-self-end mb-2'), 'd-flex flex-wrap');
            $list .= html_writer::tag('article', html_writer::tag('h4', s($name), ['class' => 'h6']) .
                self::form($uuid, 'absences', ['absence' => (string)$row->uuid, 'revision' => (int)$row->revision], $edit),
                ['class' => 'border-bottom pt-2', 'data-dailyreport-absence' => (string)$row->uuid,
                    'data-dailyreport-absence-user' => (int)$row->userid]);
        }
        return $out . html_writer::div($list, 'bms-dailyreport-absences') .
            $OUTPUT->paging_bar($data['total'], $data['page'], daily_report_calendar_service::PAGE_SIZE,
                daily_report_ui_service::url($uuid, ['tab' => 'review', 'queue' => 'absences']), 'page');
    }
}
