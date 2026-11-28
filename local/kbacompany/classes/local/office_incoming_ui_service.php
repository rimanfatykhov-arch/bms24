<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

use html_writer;

/**
 * Presentation of the DOC-W3 incoming register. Render-only: models come from office_incoming_service, which owns
 * every access decision. All user text is escaped; every form posts the session key and the expected lockversion.
 */
final class office_incoming_ui_service {
    private static function str(string $key, $a = null): string {
        return get_string('officein_' . $key, 'local_kbacompany', $a);
    }

    private static function date(?string $iso): string {
        if ($iso === null || $iso === '') { return '—'; }
        [$y, $m, $d] = explode('-', $iso);
        return $d . '.' . $m . '.' . $y;
    }

    private static function time(?int $time): string {
        return $time ? userdate($time, get_string('strftimedatetimeshort', 'langconfig')) : '—';
    }

    private static function status(string $status): string {
        return html_writer::tag('span', s(self::str('status_' . $status)),
            ['class' => 'bms-officein-status is-' . $status, 'data-officein-status' => $status]);
    }

    private static function form(\moodle_url $action, string $operation, string $body, array $hidden = [],
            array $attrs = []): string {
        $fields = html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]) .
            html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'operation', 'value' => $operation]);
        foreach ($hidden as $name => $value) {
            $fields .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => (string)$value]);
        }
        return html_writer::tag('form', $fields . $body, ['method' => 'post', 'action' => $action->out(false),
            'data-officein-form' => $operation] + $attrs);
    }

    private static function field(string $name, string $label, string $control, bool $required = false): string {
        $text = s($label) . ($required ? ' ' . html_writer::tag('span', '*', ['class' => 'bms-officein-required',
            'aria-hidden' => 'true']) : '');
        return html_writer::div(html_writer::tag('label', $text, ['for' => 'officein-' . $name]) . $control,
            'bms-officein-field');
    }

    private static function input(string $name, string $value, array $attrs = []): string {
        return html_writer::empty_tag('input', ['type' => 'text', 'name' => $name, 'id' => 'officein-' . $name,
            'value' => $value, 'class' => 'form-control'] + $attrs);
    }

    /** The metadata fields shared by the new card, the draft edit and the correction forms. */
    private static function metadata_fields(array $values): string {
        $channels = '';
        foreach (office_incoming_service::CHANNELS as $channel) {
            $channels .= html_writer::tag('option', s(self::str('channel_' . $channel)),
                ['value' => $channel, 'selected' => ($values['channel'] ?? 'post') === $channel ? 'selected' : null]);
        }
        return html_writer::div(
            self::field('subject', self::str('field_subject'),
                self::input('subject', (string)($values['subject'] ?? ''), ['maxlength' => 255, 'required' => 'required']), true) .
            self::field('correspondent', self::str('field_correspondent'),
                self::input('correspondent', (string)($values['correspondent'] ?? ''), ['maxlength' => 255,
                    'required' => 'required']), true) .
            self::field('externalnumber', self::str('field_externalnumber'),
                self::input('externalnumber', (string)($values['externalnumber'] ?? ''), ['maxlength' => 100])) .
            self::field('externaldate', self::str('field_externaldate'),
                self::input('externaldate', (string)($values['externaldate'] ?? ''), ['type' => 'date'])) .
            self::field('receivedon', self::str('field_receivedon'),
                self::input('receivedon', (string)($values['receivedon'] ?? ''), ['type' => 'date',
                    'max' => (new \DateTimeImmutable('now', \core_date::get_server_timezone_object()))->format('Y-m-d')])) .
            self::field('channel', self::str('field_channel'), html_writer::tag('select', $channels,
                ['name' => 'channel', 'id' => 'officein-channel', 'class' => 'form-control'])) .
            self::field('summary', self::str('field_summary'), html_writer::tag('textarea',
                s((string)($values['summary'] ?? '')), ['name' => 'summary', 'id' => 'officein-summary',
                    'rows' => 4, 'maxlength' => 4000, 'class' => 'form-control'])),
            'bms-officein-fields');
    }

    private static function header(string $uuid, string $title, string $lead = ''): string {
        $out = html_writer::start_tag('header', ['class' => 'bms24-ws-header']);
        $out .= html_writer::tag('h1', s($title));
        if ($lead !== '') { $out .= html_writer::tag('p', s($lead), ['class' => 'bms24-ws-lead']); }
        $out .= html_writer::end_tag('header');
        return $out . office_workspace_ui_service::section_nav($uuid, 'incoming', true);
    }

    private static function error(?string $message): string {
        return $message ? html_writer::div(s($message), 'alert alert-danger', ['role' => 'alert',
            'data-officein-error' => '1']) : '';
    }

    public static function list_page(string $uuid, array $model, array $filters): string {
        $out = html_writer::start_div('bms24-ws bms-office bms-officein', ['data-officein-list' => '1']);
        $out .= self::header($uuid, self::str('title'), self::str('intro'));
        $tools = '';
        if ($model['rights']['create']) {
            $tools .= html_writer::link(office_incoming_service::url($uuid, ['view' => 'new']), s(self::str('new')),
                ['class' => 'btn btn-primary bms-officein-new', 'data-officein-new' => '1']);
        }
        $options = html_writer::tag('option', s(self::str('filter_all')), ['value' => '']);
        foreach (office_incoming_service::STATUSES as $status) {
            $options .= html_writer::tag('option', s(self::str('status_' . $status)), ['value' => $status,
                'selected' => ($filters['status'] ?? '') === $status ? 'selected' : null]);
        }
        $tools .= html_writer::tag('form',
            html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'uuid', 'value' => $uuid]) .
            html_writer::tag('label', s(self::str('filter_status')), ['for' => 'officein-filter-status', 'class' => 'sr-only']) .
            html_writer::tag('select', $options, ['name' => 'status', 'id' => 'officein-filter-status', 'class' => 'form-control']) .
            html_writer::tag('label', s(self::str('filter_q')), ['for' => 'officein-filter-q', 'class' => 'sr-only']) .
            html_writer::empty_tag('input', ['type' => 'search', 'name' => 'q', 'id' => 'officein-filter-q',
                'value' => (string)($filters['q'] ?? ''), 'class' => 'form-control', 'maxlength' => 100,
                'placeholder' => self::str('filter_q')]) .
            html_writer::tag('button', s(self::str('filter_apply')), ['type' => 'submit', 'class' => 'btn btn-outline-primary']),
            ['method' => 'get', 'action' => office_incoming_service::url($uuid)->out_omit_querystring(),
             'class' => 'bms-officein-filter', 'role' => 'search']);
        $out .= html_writer::div($tools, 'bms-officein-tools');
        if (!$model['rights']['create']) {
            $out .= html_writer::tag('p', s(self::str('norights')), ['class' => 'bms24-ws-empty', 'data-officein-norights' => '1']);
        } else if (!$model['rows']) {
            $out .= html_writer::tag('p', s(self::str('empty')), ['class' => 'bms24-ws-empty', 'data-officein-empty' => '1']);
        } else {
            $head = '';
            foreach (['number', 'receivedon', 'correspondent', 'subject', 'status'] as $col) {
                $head .= html_writer::tag('th', s(self::str('col_' . $col)), ['scope' => 'col']);
            }
            $body = '';
            foreach ($model['rows'] as $row) {
                $url = office_incoming_service::url($uuid, ['card' => $row['uuid']]);
                $number = $row['regnumber'] ?? self::str('nonumber');
                $body .= html_writer::tag('tr',
                    html_writer::tag('td', html_writer::link($url, s($number)), ['data-label' => self::str('col_number')]) .
                    html_writer::tag('td', s(self::date($row['receivedon'])), ['data-label' => self::str('col_receivedon')]) .
                    html_writer::tag('td', s($row['correspondent']), ['data-label' => self::str('col_correspondent')]) .
                    html_writer::tag('td', html_writer::link($url, s($row['subject'])), ['data-label' => self::str('col_subject')]) .
                    html_writer::tag('td', self::status($row['status']), ['data-label' => self::str('col_status')]),
                    ['data-officein-row' => $row['uuid']]);
            }
            $out .= html_writer::div(html_writer::tag('table',
                html_writer::tag('caption', s(self::str('caption', $model['total'])), ['class' => 'sr-only']) .
                html_writer::tag('thead', html_writer::tag('tr', $head)) . html_writer::tag('tbody', $body),
                ['class' => 'table bms-officein-table', 'data-officein-total' => $model['total']]), 'bms-officein-tablewrap');
            if ($model['total'] > office_incoming_service::PAGE_SIZE) {
                $out .= html_writer::tag('p', s(self::str('paged', (object)['shown' => count($model['rows']),
                    'total' => $model['total']])), ['class' => 'bms24-ws-note']);
            }
        }
        $out .= html_writer::tag('p', s(self::str('scope_note')), ['class' => 'bms24-ws-note']);
        return $out . html_writer::end_div();
    }

    public static function new_page(string $uuid, string $createkey, array $values, ?string $error): string {
        $out = html_writer::start_div('bms24-ws bms-office bms-officein', ['data-officein-newcard' => '1']);
        $out .= self::header($uuid, self::str('new'));
        $out .= self::error($error);
        $body = self::metadata_fields($values) .
            html_writer::div(html_writer::tag('button', s(self::str('create')), ['type' => 'submit', 'class' => 'btn btn-primary']) .
                html_writer::link(office_incoming_service::url($uuid), s(get_string('cancel')), ['class' => 'btn btn-link']),
                'bms-officein-actions');
        $out .= self::form(office_incoming_service::url($uuid), 'create', $body, ['createkey' => $createkey],
            ['class' => 'bms-officein-form']);
        $out .= html_writer::tag('p', s(self::str('create_note')), ['class' => 'bms24-ws-note']);
        return $out . html_writer::end_div();
    }

    public static function not_found(string $uuid): string {
        return html_writer::div(self::header($uuid, self::str('title')) .
            html_writer::div(s(self::str('err_notfound')), 'alert alert-warning', ['role' => 'alert',
                'data-officein-notfound' => '1']) .
            html_writer::tag('p', html_writer::link(office_incoming_service::url($uuid), s(self::str('back')))),
            'bms24-ws bms-office bms-officein');
    }

    private static function person(array $card, int $id): string {
        return $id > 0 ? ($card['people'][$id] ?? self::str('unknownuser')) : self::str('unknownuser');
    }

    private static function file_link(string $uuid, int $id, string $area, array $file): string {
        $url = office_incoming_service::file_url($uuid, $area, $id, $file['sha256'], $file['filename']);
        return html_writer::link($url, s($file['filename']), ['data-officein-file' => $area]) . ' ' .
            html_writer::tag('span', s(display_size($file['bytes'])), ['class' => 'bms24-ws-meta']);
    }

    public static function card_page(string $uuid, int $recordid, array $card, string $registerkey, ?string $error,
            ?array $values): string {
        $url = office_incoming_service::url($uuid, ['card' => $card['uuid']]);
        $d = $card['data'];
        $title = $card['regnumber'] ?? self::str('nonumber');
        $out = html_writer::start_div('bms24-ws bms-office bms-officein', ['data-officein-card' => $card['uuid'],
            'data-officein-lockversion' => $card['lockversion']]);
        $out .= self::header($uuid, $title . ' — ' . $d['subject']);
        $out .= self::error($error);

        // Registration block: number, server time, registrar, status.
        $reg = html_writer::tag('dt', s(self::str('col_number'))) .
            html_writer::tag('dd', s($card['regnumber'] ?? self::str('nonumber')), ['data-officein-number' => '1']) .
            html_writer::tag('dt', s(self::str('col_status'))) . html_writer::tag('dd', self::status($card['status'])) .
            html_writer::tag('dt', s(self::str('registeredat'))) . html_writer::tag('dd', s(self::time($card['registeredat']))) .
            html_writer::tag('dt', s(self::str('registeredby'))) . html_writer::tag('dd',
                s($card['registeredat'] ? self::person($card, $card['registeredby']) : '—')) .
            html_writer::tag('dt', s(self::str('createdby'))) . html_writer::tag('dd',
                s(self::person($card, $card['createdby']) . ', ' . self::time($card['timecreated'])));
        if ($card['status'] === 'cancelled') {
            $reg .= html_writer::tag('dt', s(self::str('cancelreason'))) .
                html_writer::tag('dd', s($card['cancelreason'] ?? '—'), ['data-officein-cancelreason' => '1']);
        }
        $out .= html_writer::tag('section', html_writer::tag('h2', s(self::str('section_registration'))) .
            html_writer::tag('dl', $reg, ['class' => 'bms-officein-dl']), ['class' => 'bms24-ws-card']);

        // Attributes (current revision).
        $attr = '';
        foreach (['correspondent', 'externalnumber', 'externaldate', 'receivedon', 'channel', 'summary'] as $f) {
            $value = $d[$f];
            if ($f === 'externaldate' || $f === 'receivedon') { $value = self::date($value); }
            if ($f === 'channel') { $value = self::str('channel_' . $value); }
            $attr .= html_writer::tag('dt', s(self::str('field_' . $f))) .
                html_writer::tag('dd', $value === '' ? '—' : nl2br(s($value)), ['data-officein-field' => $f]);
        }
        $out .= html_writer::tag('section', html_writer::tag('h2', s(self::str('section_attributes'))) .
            html_writer::tag('dl', $attr, ['class' => 'bms-officein-dl']) .
            html_writer::tag('p', s(self::str('revision', $card['currentrev'])), ['class' => 'bms24-ws-meta']),
            ['class' => 'bms24-ws-card']);

        // Files: the original (immutable once set) and attachments.
        $files = html_writer::tag('h3', s(self::str('original')));
        $files .= $card['original'] ? html_writer::tag('p', self::file_link($uuid, $recordid, 'office_original', $card['original']),
            ['data-officein-original' => $card['original']['sha256']])
            : html_writer::tag('p', s(self::str('nooriginal')), ['class' => 'bms24-ws-empty', 'data-officein-nooriginal' => '1']);
        $files .= html_writer::tag('h3', s(self::str('attachments')));
        if ($card['attachments']) {
            $items = '';
            foreach ($card['attachments'] as $file) {
                $items .= html_writer::tag('li', self::file_link($uuid, $recordid, 'office_attachment', $file));
            }
            $files .= html_writer::tag('ul', $items, ['class' => 'bms24-ws-list']);
        } else {
            $files .= html_writer::tag('p', s(self::str('noattachments')), ['class' => 'bms24-ws-empty']);
        }
        foreach (['original' => 'upload_original', 'attach' => 'upload_attachment'] as $can => $label) {
            if (empty($card['can'][$can])) { continue; }
            $kind = $can === 'original' ? 'original' : 'attachment';
            $files .= self::form($url, 'file', html_writer::div(
                html_writer::tag('label', s(self::str($label)), ['for' => 'officein-file-' . $kind]) .
                html_writer::empty_tag('input', ['type' => 'file', 'name' => 'officefile', 'id' => 'officein-file-' . $kind,
                    'required' => 'required', 'accept' => '.pdf,.jpg,.jpeg,.png,.docx', 'class' => 'form-control']) .
                html_writer::tag('button', s(self::str('upload')), ['type' => 'submit', 'class' => 'btn btn-outline-primary']),
                'bms-officein-upload'), ['card' => $card['uuid'], 'lockversion' => $card['lockversion'], 'kind' => $kind],
                ['enctype' => 'multipart/form-data']);
        }
        $files .= html_writer::tag('p', s(self::str('upload_rules', display_size(office_upload::max_bytes()))),
            ['class' => 'bms24-ws-meta']);
        $out .= html_writer::tag('section', html_writer::tag('h2', s(self::str('section_files'))) . $files,
            ['class' => 'bms24-ws-card']);

        // Actions.
        $actions = '';
        if ($card['can']['register']) {
            $actions .= self::form($url, 'register', html_writer::tag('button', s(self::str('register')),
                ['type' => 'submit', 'class' => 'btn btn-primary', 'data-officein-register' => '1']),
                ['card' => $card['uuid'], 'lockversion' => $card['lockversion'], 'registerkey' => $registerkey]);
            $actions .= html_writer::tag('p', s(self::str('register_note')), ['class' => 'bms24-ws-meta']);
        }
        if ($card['can']['edit'] || $card['can']['correct']) {
            $correct = $card['can']['correct'];
            $body = self::metadata_fields($values ?? $d);
            if ($correct) {
                $body .= self::field('reason', self::str('field_reason'), html_writer::tag('textarea', '',
                    ['name' => 'reason', 'id' => 'officein-reason', 'rows' => 2, 'maxlength' => 1000,
                     'required' => 'required', 'class' => 'form-control']), true);
            }
            $body .= html_writer::tag('button', s(self::str($correct ? 'correct' : 'save')),
                ['type' => 'submit', 'class' => 'btn btn-outline-primary']);
            $actions .= html_writer::tag('details', html_writer::tag('summary', s(self::str($correct ? 'correct' : 'edit'))) .
                self::form($url, 'save', $body, ['card' => $card['uuid'], 'lockversion' => $card['lockversion']],
                    ['class' => 'bms-officein-form']), ['class' => 'bms-officein-details', 'open' => $values !== null ? 'open' : null]);
        }
        if ($card['can']['cancel']) {
            $needreason = $card['status'] === 'registered';
            $actions .= html_writer::tag('details', html_writer::tag('summary', s(self::str('cancel'))) .
                self::form($url, 'cancel',
                    self::field('cancelreason', self::str('field_cancelreason'), html_writer::tag('textarea', '',
                        ['name' => 'reason', 'id' => 'officein-cancelreason', 'rows' => 2, 'maxlength' => 1000,
                         'required' => $needreason ? 'required' : null, 'class' => 'form-control']), $needreason) .
                    html_writer::tag('p', s(self::str($needreason ? 'cancel_note_registered' : 'cancel_note_draft')),
                        ['class' => 'bms24-ws-meta']) .
                    html_writer::tag('button', s(self::str('cancel_confirm')), ['type' => 'submit', 'class' => 'btn btn-outline-danger']),
                    ['card' => $card['uuid'], 'lockversion' => $card['lockversion']]),
                ['class' => 'bms-officein-details']);
        }
        if ($actions !== '') {
            $out .= html_writer::tag('section', html_writer::tag('h2', s(self::str('section_actions'))) . $actions,
                ['class' => 'bms24-ws-card', 'data-officein-actions' => '1']);
        }

        // Journal (append-only).
        $items = '';
        foreach (array_reverse($card['journal']) as $entry) {
            $detail = '';
            if ($entry['action'] === 'registered') {
                $detail = (string)($entry['new']['number'] ?? '');
            } else if (in_array($entry['action'], ['corrected', 'updated'], true)) {
                $changes = [];
                foreach ((array)$entry['new'] as $field => $value) {
                    if (!in_array($field, office_incoming_service::FIELDS, true)) { continue; }
                    $changes[] = self::str('field_' . $field) . ': «' . (string)($entry['old'][$field] ?? '—') . '» → «' .
                        (string)($value ?? '—') . '»';
                }
                if (!empty($entry['new']['reason'])) { $changes[] = self::str('field_reason') . ': ' . $entry['new']['reason']; }
                $detail = implode('; ', $changes);
            } else if (in_array($entry['action'], ['original', 'attachment'], true)) {
                $detail = (string)($entry['new']['filename'] ?? '');
            } else if ($entry['action'] === 'cancelled') {
                $detail = (string)($entry['new']['reason'] ?? '');
            }
            $items .= html_writer::tag('li',
                html_writer::tag('strong', s(self::str('log_' . $entry['action']))) . ' ' .
                html_writer::tag('span', s(self::person($card, $entry['actorid']) . ' · ' . self::time($entry['timecreated'])),
                    ['class' => 'bms24-ws-meta']) .
                ($detail !== '' ? html_writer::div(s($detail), 'bms-officein-logdetail') : ''),
                ['data-officein-log' => $entry['action']]);
        }
        $out .= html_writer::tag('section', html_writer::tag('h2', s(self::str('section_journal'))) .
            html_writer::tag('ol', $items, ['class' => 'bms24-ws-list bms-officein-journal', 'reversed' => 'reversed']),
            ['class' => 'bms24-ws-card', 'data-officein-journal' => count($card['journal'])]);
        $out .= html_writer::tag('p', html_writer::link(office_incoming_service::url($uuid), s(self::str('back'))));
        return $out . html_writer::end_div();
    }
}
