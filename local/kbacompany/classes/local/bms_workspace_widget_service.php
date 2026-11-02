<?php
// BMS24 RC29/1804: per-user/per-company read-only workspace layout preferences.
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Whitelisted card layout. User preferences change presentation, NEVER capabilities.
 * The service stores opaque widget IDs, not dynamic PHP/URLs/SQL.
 */
final class bms_workspace_widget_service {
    public const CONTRACT_VERSION = '1.0.0';
    public const MAX_WIDGETS = 9;
    public const DEFAULT_ORDER = [
        'today', 'plan', 'actions', 'overview', 'attention', 'tasks',
        'management', 'architecture', 'company',
    ];

    public static function preference_key(string $companyuuid): string {
        return 'local_kbacompany_widgets_' . substr(hash('sha256', $companyuuid), 0, 24);
    }

    /** Values are also keys in lang/ru and lang/en, not user-provided labels. */
    public static function available(bool $canmanage): array {
        $keys = self::DEFAULT_ORDER;
        if (!$canmanage) {
            $keys = array_values(array_diff($keys, ['management']));
        }
        return $keys;
    }

    public static function defaults(array $available): array {
        $available = self::safe_keys($available);
        return ['order' => $available, 'hidden' => [], 'visible' => $available];
    }

    private static function safe_keys(array $input): array {
        $result = [];
        foreach ($input as $key) {
            if (is_string($key) && in_array($key, self::DEFAULT_ORDER, true) &&
                    !in_array($key, $result, true)) {
                $result[] = $key;
            }
        }
        return $result;
    }

    /** Invalid/old preferences become the safe default, without corrupting the DB. */
    public static function normalize($decoded, array $available): array {
        $available = self::safe_keys($available);
        if (!is_array($decoded) || !isset($decoded['order'], $decoded['hidden']) ||
                !is_array($decoded['order']) || !is_array($decoded['hidden'])) {
            return self::defaults($available);
        }
        $order = array_values(array_intersect(self::safe_keys($decoded['order']), $available));
        foreach ($available as $key) {
            if (!in_array($key, $order, true)) {
                $order[] = $key;
            }
        }
        $hidden = array_values(array_intersect(self::safe_keys($decoded['hidden']), $available));
        $visible = array_values(array_diff($order, $hidden));
        if (!$visible && $available) {
            // Never strand the user on a totally empty home screen.
            $hidden = array_values(array_diff($hidden, ['today']));
            $visible = array_values(array_diff($order, $hidden));
        }
        return ['order' => $order, 'hidden' => $hidden, 'visible' => $visible];
    }

    public static function current(string $companyuuid, int $userid, array $available): array {
        $raw = (string)get_user_preferences(self::preference_key($companyuuid), '', $userid);
        return self::normalize(json_decode($raw, true), $available);
    }

    /**
     * Build from a keyboard-accessible form. Each select is an ordinal priority;
     * ties are stable in the existing layout order. Unknown keys are rejected.
     */
    public static function prepare(array $positions, array $selected, array $available, array $baseline): array {
        $available = self::safe_keys($available);
        foreach (array_keys($positions) as $key) {
            if (!is_string($key) || !in_array($key, $available, true)) {
                throw new \moodle_exception('bmswidgets_invalid', 'local_kbacompany');
            }
        }
        foreach ($selected as $key) {
            if (!is_string($key) || !in_array($key, $available, true)) {
                throw new \moodle_exception('bmswidgets_invalid', 'local_kbacompany');
            }
        }
        $original = self::normalize($baseline, $available)['order'];
        $sort = [];
        foreach ($original as $i => $key) {
            $position = $positions[$key] ?? ($i + 1);
            if (!is_numeric($position) || (int)$position < 1 || (int)$position > count($available)) {
                throw new \moodle_exception('bmswidgets_invalid', 'local_kbacompany');
            }
            $sort[] = ['key' => $key, 'priority' => (int)$position, 'baseline' => $i];
        }
        usort($sort, static function(array $a, array $b): int {
            return ($a['priority'] <=> $b['priority']) ?: ($a['baseline'] <=> $b['baseline']);
        });
        $order = array_column($sort, 'key');
        $selected = self::safe_keys($selected);
        if (!$selected) {
            throw new \moodle_exception('bmswidgets_empty', 'local_kbacompany');
        }
        return self::normalize(['order' => $order, 'hidden' => array_values(array_diff($order, $selected))], $available);
    }

    public static function save(string $companyuuid, int $userid, array $layout, array $available): void {
        $safe = self::normalize($layout, $available);
        set_user_preference(self::preference_key($companyuuid), json_encode([
            'order' => $safe['order'], 'hidden' => $safe['hidden'],
        ]), $userid);
    }

    public static function reset(string $companyuuid, int $userid): void {
        unset_user_preference(self::preference_key($companyuuid), $userid);
    }
}
