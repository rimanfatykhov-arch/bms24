<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * BMS24 UI Kit 1.0 presentation contract. Never reads or writes business data.
 * Callers retain responsibility for ACL, actions and any HTML passed as trusted slots.
 * Labels and URLs are escaped by Moodle Mustache, class variants are allowlisted.
 */
final class bms24_ui_kit_service {
    public const CONTRACT_VERSION = '1.0.0-beta.1';
    private const FRAGMENTS = [
        'ui_button', 'ui_status', 'ui_field', 'ui_filterbar', 'ui_table',
        'ui_tabs', 'ui_pagination', 'ui_alert',
    ];
    private const VARIANTS = ['primary', 'secondary', 'quiet', 'danger'];
    private const STATES = ['new', 'open', 'doing', 'review', 'done', 'returned', 'cancelled', 'deleted', 'paused'];
    private const ALERTS = ['info', 'warning', 'error', 'success'];

    public static function fragment(string $name, array $data): string {
        if (!in_array($name, self::FRAGMENTS, true)) {
            throw new \coding_exception('Unsupported BMS24 UI Kit fragment');
        }
        return bms24_shell_service::fragment($name, $data);
    }

    public static function button(\moodle_url $url, string $label, string $variant = 'secondary'): string {
        if (!in_array($variant, self::VARIANTS, true)) {
            throw new \coding_exception('Unsupported BMS24 button variant');
        }
        return self::fragment('ui_button', [
            'url' => $url->out(false), 'label' => $label, 'variant' => $variant,
        ]);
    }

    public static function status(string $label, string $state): string {
        if (!in_array($state, self::STATES, true)) {
            throw new \coding_exception('Unsupported BMS24 status variant');
        }
        return self::fragment('ui_status', ['label' => $label, 'state' => $state]);
    }

    public static function alert(string $message, string $variant = 'info'): string {
        if (!in_array($variant, self::ALERTS, true)) {
            throw new \coding_exception('Unsupported BMS24 alert variant');
        }
        return self::fragment('ui_alert', ['message' => $message, 'variant' => $variant]);
    }

    /** UI-only schema. No hidden data binding or business validation. */
    public static function field(array $spec): string {
        return self::fragment('ui_field', self::field_data($spec));
    }

    /** Escaped Mustache data only. */
    private static function field_data(array $spec): array {
        $type = (string)($spec['type'] ?? 'text');
        if (!in_array($type, ['text', 'search', 'date', 'number', 'email', 'select', 'textarea'], true)) {
            throw new \coding_exception('Unsupported BMS24 field type');
        }
        $id = (string)($spec['id'] ?? '');
        $name = (string)($spec['name'] ?? '');
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,79}$/', $id) ||
                !preg_match('/^[A-Za-z][A-Za-z0-9_\[\]-]{0,79}$/', $name)) {
            throw new \coding_exception('Invalid BMS24 field id or name');
        }
        $value = (string)($spec['value'] ?? '');
        $data = [
            'id' => $id, 'name' => $name, 'label' => (string)($spec['label'] ?? ''),
            'type' => $type, 'value' => $value, 'help' => (string)($spec['help'] ?? ''),
            'required' => !empty($spec['required']),
            'placeholder' => (string)($spec['placeholder'] ?? ''),
            // Bound numeric HTML constraints: no arbitrary attribute injection.
            'maxlength' => ($type === 'text' || $type === 'search' || $type === 'textarea')
                && isset($spec['maxlength']) ? max(1, min(12000, (int)$spec['maxlength'])) : 0,
            'isselect' => $type === 'select', 'istextarea' => $type === 'textarea',
            'istext' => $type !== 'select' && $type !== 'textarea',
            'options' => [],
        ];
        if ($type === 'select') {
            foreach (($spec['options'] ?? []) as $optionvalue => $optionlabel) {
                $data['options'][] = [
                    'value' => (string)$optionvalue, 'label' => (string)$optionlabel,
                    'selected' => (string)$optionvalue === $value,
                ];
            }
        }
        return $data;
    }

    /** Build a GET filterbar from typed, escaped Mustache data; no raw HTML slots. */
    public static function filterbar(\moodle_url $action, string $label, array $fields,
            array $hidden, \moodle_url $reseturl, string $submitlabel, string $resetlabel): string {
        $fielddata = [];
        foreach ($fields as $field) {
            $fielddata[] = self::field_data($field);
        }
        $hiddendata = [];
        foreach ($hidden as $name => $value) {
            if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,79}$/', (string)$name)) {
                throw new \coding_exception('Invalid BMS24 filter hidden name');
            }
            $hiddendata[] = ['name' => (string)$name, 'value' => (string)$value];
        }
        return self::fragment('ui_filterbar', [
            'action' => $action->out(false), 'label' => $label, 'fields' => $fielddata,
            'hidden' => $hiddendata, 'submitlabel' => $submitlabel,
            'resetlabel' => $resetlabel, 'reseturl' => $reseturl->out(false),
        ]);
    }

    /** Only Moodle-generated URLs are accepted for navigation. */
    private static function safe_url($value): string {
        if ($value === null || $value === '') {
            return '';
        }
        if (!$value instanceof \moodle_url) {
            throw new \coding_exception('BMS24 navigation URL must be moodle_url');
        }
        return $value->out(false);
    }

    /** All labels and URLs are Mustache-escaped. */
    public static function table(string $label, array $headers, array $rows): string {
        $cols = [];
        foreach ($headers as $heading) {
            $cols[] = ['label' => (string)$heading];
        }
        $data = [];
        foreach ($rows as $row) {
            $cells = [];
            if (count($row) !== count($headers)) {
                throw new \coding_exception('Invalid BMS24 table columns');
            }
            foreach ($row as $cell) {
                $cells[] = is_array($cell) ? [
                    'label' => (string)($cell['label'] ?? ''),
                    'url' => self::safe_url($cell['url'] ?? null),
                ] : ['label' => (string)$cell];
            }
            $data[] = ['cells' => $cells];
        }
        return self::fragment('ui_table', ['label' => $label, 'headers' => $cols, 'rows' => $data]);
    }

    public static function pagination(string $label, array $items): string {
        $data = [];
        foreach ($items as $item) {
            $data[] = [
                'url' => self::safe_url($item['url'] ?? null), 'label' => (string)($item['label'] ?? ''),
                'arialabel' => (string)($item['arialabel'] ?? $item['label'] ?? ''),
                'active' => !empty($item['active']),
            ];
        }
        return self::fragment('ui_pagination', ['label' => $label, 'items' => $data]);
    }
}
