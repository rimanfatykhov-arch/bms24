<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

use html_writer;

/**
 * Presentation of the DOC-W2 Office workspace. Render-only: it receives the authorized model from
 * office_workspace_service and never reads business data or decides access. All user text is escaped.
 */
final class office_workspace_ui_service {
    private static function str(string $key, $a = null): string {
        return get_string('officews_' . $key, 'local_kbacompany', $a);
    }

    /** Quick menu inside the workspace. Planned sections are plain text with a "planned" mark, never links. */
    public static function section_nav(string $companyuuid, string $active): string {
        $routes = office_workspace_service::routes($companyuuid);
        $items = '';
        foreach (['overview', 'documents', 'review', 'execution', 'meetings', 'policies'] as $key) {
            $current = $key === $active;
            $items .= html_writer::tag('li', html_writer::link($routes[$key], self::str('section_' . $key), [
                'class' => 'bms24-wsnav-link' . ($current ? ' is-active' : ''),
                'aria-current' => $current ? 'page' : null,
            ]));
        }
        foreach (office_workspace_service::PLANNED_SECTIONS as $key) {
            $items .= html_writer::tag('li', html_writer::tag('span',
                s(self::str('section_' . $key)) . ' ' .
                html_writer::tag('small', s(self::str('planned')), ['class' => 'bms24-wsnav-plannedmark']), [
                    'class' => 'bms24-wsnav-planned', 'aria-disabled' => 'true',
                    'title' => self::str('planned_hint'),
                    'data-office-planned' => $key,
                ]));
        }
        return html_writer::tag('nav', html_writer::tag('ul', $items, ['class' => 'bms24-wsnav-list']), [
            'class' => 'bms24-wsnav bms-context-quicknav', 'aria-label' => self::str('nav_label'),
            'data-office-nav' => '1',
        ]);
    }

    public static function render(array $model): string {
        $uuid = $model['company']['uuid'];
        $routes = office_workspace_service::routes($uuid);
        $c = $model['counts'];
        $out = html_writer::start_div('bms24-ws bms-office', ['data-office-workspace' => '1']);
        $out .= html_writer::start_tag('header', ['class' => 'bms24-ws-header']);
        $out .= html_writer::tag('h1', s(self::str('title')));
        $out .= html_writer::tag('p', s(self::str('intro', format_string($model['company']['name']))),
            ['class' => 'bms24-ws-lead']);
        $out .= html_writer::end_tag('header');
        $out .= self::section_nav($uuid, 'overview');

        // Counters: every value is an authorized total with a link to the owning page.
        $cards = [
            ['review', $c['review'], $routes['review']],
            ['acknowledge', $c['acknowledge'], null],
            ['execution', $c['execution'], $routes['execution']],
            ['overdue', $c['overdue'], $routes['overdue']],
            ['documents', $c['documents'], $routes['documents']],
            ['directives', $c['directives'], $routes['directives']],
        ];
        $stats = '';
        foreach ($cards as [$key, $value, $url]) {
            $body = html_writer::tag('span', s(self::str('count_' . $key)), ['class' => 'bms24-ws-statlabel']) .
                html_writer::tag('span', (string)(int)$value, ['class' => 'bms24-ws-statvalue']) .
                html_writer::tag('span', s(self::str('count_' . $key . '_hint')), ['class' => 'bms24-ws-statcaption']);
            $attrs = ['class' => 'bms24-ws-stat' . ($key === 'overdue' && $value > 0 ? ' is-warning' : ''),
                'data-office-count' => $key, 'data-value' => (int)$value];
            $stats .= html_writer::tag('li', $url ? html_writer::link($url, $body, $attrs + ['class' => $attrs['class']])
                : html_writer::tag('div', $body, $attrs));
        }
        $out .= html_writer::tag('ul', $stats, ['class' => 'bms24-ws-stats', 'aria-label' => self::str('counts_label')]);

        $out .= html_writer::start_div('bms24-ws-grid');
        $out .= self::attention_card($model, $routes);
        $out .= self::actions_card($model, $routes);
        $out .= self::documents_card('review', $model['review'], $uuid, $c['review'], $routes['review']);
        $out .= self::ack_card($model['acknowledge'], $uuid, $c['acknowledge']);
        $out .= self::tasks_card($model['overdue'], $uuid, $c['overdue'], $routes['overdue']);
        $out .= self::documents_card('recent', $model['recent'], $uuid, $c['documents'], $routes['documents']);
        $out .= html_writer::end_div();

        $out .= html_writer::tag('p', s(self::str('scope_note')), ['class' => 'bms24-ws-note']);
        $out .= html_writer::end_div();
        return $out;
    }

    private static function card(string $key, string $body, ?\moodle_url $more = null, int $total = 0): string {
        $head = html_writer::tag('h2', s(self::str('card_' . $key)), ['id' => 'office-card-' . $key]);
        $foot = $more && $total > office_workspace_service::LIST_LIMIT
            ? html_writer::tag('p', html_writer::link($more, s(self::str('showall', $total))), ['class' => 'bms24-ws-more'])
            : '';
        return html_writer::tag('section', $head . $body . $foot, ['class' => 'bms24-ws-card',
            'aria-labelledby' => 'office-card-' . $key, 'data-office-card' => $key]);
    }

    private static function empty(string $key): string {
        return html_writer::tag('p', s(self::str('empty_' . $key)), ['class' => 'bms24-ws-empty', 'data-office-empty' => $key]);
    }

    private static function attention_card(array $model, array $routes): string {
        if (!$model['attention']) {
            return self::card('attention', self::empty('attention'));
        }
        $targets = ['overdue' => $routes['overdue'], 'review' => $routes['review'], 'acknowledge' => null,
            'decisions' => $routes['meetings']];
        $items = '';
        foreach ($model['attention'] as $item) {
            $text = s(self::str('attention_' . $item['key'], $item['count']));
            $url = $targets[$item['key']];
            $items .= html_writer::tag('li', $url ? html_writer::link($url, $text) : $text,
                ['data-office-attention' => $item['key']]);
        }
        return self::card('attention', html_writer::tag('ul', $items, ['class' => 'bms24-ws-list']));
    }

    private static function actions_card(array $model, array $routes): string {
        $links = [];
        if (!empty($model['can_manage_documents'])) {
            $links[] = html_writer::link($routes['createdocument'], s(self::str('action_createdocument')),
                ['class' => 'bms24-ws-action is-primary']);
        }
        foreach (['documents' => 'action_documents', 'execution' => 'action_execution', 'meetings' => 'action_meetings',
                'policies' => 'action_policies'] as $route => $label) {
            $links[] = html_writer::link($routes[$route], s(self::str($label)), ['class' => 'bms24-ws-action']);
        }
        return self::card('actions', html_writer::div(implode('', $links), 'bms24-ws-actions'));
    }

    private static function document_url(string $uuid, string $document): \moodle_url {
        return new \moodle_url('/local/kbacompany/documents.php', ['uuid' => $uuid, 'document' => $document]);
    }

    private static function documents_card(string $key, array $rows, string $uuid, int $total, \moodle_url $more): string {
        if (!$rows) {
            return self::card($key, self::empty($key));
        }
        $items = '';
        foreach ($rows as $row) {
            $status = get_string('document_status_' . $row['status'], 'local_kbacompany');
            $items .= html_writer::tag('li',
                html_writer::link(self::document_url($uuid, $row['uuid']), s($row['code'] . ' — ' . $row['title'])) .
                html_writer::tag('span', s($status . ' · ' . self::str('revision', $row['revision']) . ' · ' .
                    userdate($row['timemodified'], get_string('strftimedatefullshort', 'langconfig'))),
                    ['class' => 'bms24-ws-meta']));
        }
        return self::card($key, html_writer::tag('ul', $items, ['class' => 'bms24-ws-list']), $more, $total);
    }

    private static function ack_card(array $rows, string $uuid, int $total): string {
        if (!$rows) {
            return self::card('acknowledge', self::empty('acknowledge'));
        }
        $items = '';
        foreach ($rows as $row) {
            $items .= html_writer::tag('li',
                html_writer::link(self::document_url($uuid, $row['uuid']), s($row['code'] . ' — ' . $row['title'])) .
                html_writer::tag('span', s(self::str('revision', $row['revision']) . ' · ' . self::str('ack_since',
                    userdate($row['since'], get_string('strftimedatefullshort', 'langconfig')))),
                    ['class' => 'bms24-ws-meta']));
        }
        $more = $total > count($rows) ? html_writer::tag('p', s(self::str('ack_more', $total)), ['class' => 'bms24-ws-more']) : '';
        return self::card('acknowledge', html_writer::tag('ul', $items, ['class' => 'bms24-ws-list']) . $more);
    }

    private static function tasks_card(array $rows, string $uuid, int $total, \moodle_url $more): string {
        if (!$rows) {
            return self::card('overdue', self::empty('overdue'));
        }
        $items = '';
        foreach ($rows as $row) {
            $url = new \moodle_url('/local/kbacompany/tasks.php', ['uuid' => $uuid, 'task' => $row['uuid']]);
            $items .= html_writer::tag('li', html_writer::link($url, s($row['title'])) .
                html_writer::tag('span', s(self::str('due', $row['duedate'])), ['class' => 'bms24-ws-meta']));
        }
        return self::card('overdue', html_writer::tag('ul', $items, ['class' => 'bms24-ws-list']), $more, $total);
    }
}
