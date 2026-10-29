<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Presentation-only collection views for BMS registries.
 *
 * Controllers/domain services still own queries, permissions, lifecycle and URLs.
 */
class bms_collection_view_service {
    public const CONTRACT_VERSION = '0.1';
    public const VIEWS = ['list', 'kanban', 'analytics'];

    public static function normalise_view(string $view): string {
        return in_array($view, self::VIEWS, true) ? $view : 'list';
    }

    public static function switcher(string $path, array $params, string $active): string {
        $active = self::normalise_view($active);
        $labels = [
            'list' => get_string('bmsview_list', 'local_kbacompany'),
            'kanban' => get_string('bmsview_kanban', 'local_kbacompany'),
            'analytics' => get_string('bmsview_analytics', 'local_kbacompany'),
        ];
        $html = '';
        foreach (self::VIEWS as $view) {
            $url = new \moodle_url($path, array_merge($params, ['view' => $view]));
            $class = 'btn btn-sm ' . ($view === $active ? 'btn-secondary' : 'btn-outline-secondary');
            $html .= \html_writer::link($url, $labels[$view], [
                'class' => $class,
                'aria-current' => $view === $active ? 'page' : null,
            ]);
        }
        return \html_writer::div($html, 'btn-group bms-collection-view-switcher', ['role' => 'group']);
    }

    public static function toolbar(string $path, array $params, string $active, string $query = '', string $actionshtml = ''): string {
        $form = \html_writer::start_tag('form', ['method' => 'get', 'action' => (new \moodle_url($path))->out(false),
            'class' => 'bms-collection-search']);
        foreach ($params as $name => $value) {
            if ($name === 'q' || $name === 'view') { continue; }
            $form .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]);
        }
        $form .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'view', 'value' => self::normalise_view($active)]);
        $form .= \html_writer::empty_tag('input', [
            'type' => 'search', 'name' => 'q', 'value' => $query,
            'class' => 'form-control form-control-sm', 'placeholder' => get_string('bmsview_search', 'local_kbacompany'),
        ]);
        $form .= \html_writer::tag('button', get_string('search'), ['type' => 'submit', 'class' => 'btn btn-sm btn-outline-secondary']);
        if ($query !== '') {
            $clearparams = $params;
            $clearparams['view'] = self::normalise_view($active);
            $form .= \html_writer::link(new \moodle_url($path, $clearparams), get_string('reset'), ['class' => 'btn btn-sm btn-link']);
        }
        $form .= \html_writer::end_tag('form');
        return \html_writer::div(
            \html_writer::div($actionshtml . $form, 'bms-collection-toolbar-main') .
            self::switcher($path, $params, $active),
            'bms-collection-toolbar');
    }

    public static function filter_rows(array $rows, string $query, array $fields): array {
        $query = trim($query);
        if ($query === '') { return $rows; }
        $needle = \core_text::strtolower($query);
        return array_values(array_filter($rows, static function(array $row) use ($fields, $needle): bool {
            foreach ($fields as $field) {
                $value = (string)($row[$field] ?? '');
                if ($value !== '' && strpos(\core_text::strtolower(strip_tags($value)), $needle) !== false) {
                    return true;
                }
            }
            return false;
        }));
    }

    /** @param array<string,array{label:string,cards:array<int,string>}> $columns */
    public static function kanban(array $columns): string {
        $html = \html_writer::start_div('bms-kanban');
        foreach ($columns as $column) {
            $cards = $column['cards'] ?? [];
            $html .= \html_writer::start_div('bms-kanban-column');
            $html .= \html_writer::div(
                \html_writer::span((string)($column['label'] ?? ''), 'bms-kanban-column-title') .
                \html_writer::span((string)count($cards), 'badge badge-light'),
                'bms-kanban-column-header');
            $html .= \html_writer::start_div('bms-kanban-column-body');
            if ($cards) {
                foreach ($cards as $card) { $html .= $card; }
            } else {
                $html .= \html_writer::div(get_string('bmsview_emptycolumn', 'local_kbacompany'), 'bms-kanban-empty');
            }
            $html .= \html_writer::end_div();
            $html .= \html_writer::end_div();
        }
        $html .= \html_writer::end_div();
        return $html;
    }

    public static function card(string $titlehtml, array $meta = [], string $body = '', string $class = ''): string {
        $metahtml = '';
        foreach ($meta as $item) {
            if ($item === '' || $item === null) { continue; }
            $metahtml .= \html_writer::span((string)$item, 'bms-kanban-card-meta-item');
        }
        return \html_writer::div(
            \html_writer::div($titlehtml, 'bms-kanban-card-title') .
            ($body !== '' ? \html_writer::div($body, 'bms-kanban-card-body') : '') .
            ($metahtml !== '' ? \html_writer::div($metahtml, 'bms-kanban-card-meta') : ''),
            'bms-kanban-card' . ($class !== '' ? ' ' . $class : ''));
    }

    /** @param array<int,array{label:string,value:string|int,caption?:string}> $metrics */
    public static function analytics(array $metrics): string {
        $html = \html_writer::start_div('bms-collection-analytics');
        foreach ($metrics as $metric) {
            $html .= \html_writer::div(
                \html_writer::div((string)$metric['label'], 'bms-collection-metric-label') .
                \html_writer::div((string)$metric['value'], 'bms-collection-metric-value') .
                (!empty($metric['caption']) ? \html_writer::div((string)$metric['caption'], 'bms-collection-metric-caption') : ''),
                'bms-collection-metric');
        }
        $html .= \html_writer::end_div();
        return $html;
    }
}
