<?php
// RC29/1810: Capability-scoped, read-only workspace view selection.
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Selection affects presentation and dependency loading, never grants access.
 * A forbidden or unrecognized scope always resolves to the original overview.
 * User widget visibility/order remains authoritative in every scope.
 */
final class bms_workspace_focus_service {
    private const PERSONAL = ['today', 'plan', 'actions', 'overview', 'attention', 'tasks'];
    private const MANAGEMENT = ['attention', 'management'];
    private const OWNER = ['attention', 'management', 'architecture', 'company'];

    public static function modes(bool $canmanagement, bool $canowner): array {
        $modes = ['all', 'personal'];
        if ($canmanagement) { $modes[] = 'management'; }
        if ($canowner) { $modes[] = 'owner'; }
        return $modes;
    }

    public static function select(string $requested, bool $canmanagement, bool $canowner): string {
        return in_array($requested, self::modes($canmanagement, $canowner), true) ? $requested : 'all';
    }

    /** Only filters previously ACL-approved and user-visible widget IDs. */
    public static function visible(array $visible, string $mode): array {
        $allowed = match ($mode) {
            'personal' => self::PERSONAL,
            'management' => self::MANAGEMENT,
            'owner' => self::OWNER,
            default => bms_workspace_widget_service::DEFAULT_ORDER,
        };
        return array_values(array_intersect($visible, $allowed));
    }

    public static function include_management_decisions(string $mode): bool {
        return $mode !== 'personal';
    }

    /** Filter AFTER taking a larger bounded source projection, before final LIMIT. */
    public static function actions(array $items, string $mode): array {
        $personal = ['bmsaction_fix_task', 'bmsaction_open_overdue', 'bmsaction_open_today',
            'bmsaction_fix_plan'];
        $management = ['bmsaction_review_task', 'bmsaction_review_plan', 'bmsaction_decide_indicator'];
        if ($mode === 'personal' || $mode === 'management' || $mode === 'owner') {
            $allow = $mode === 'personal' ? $personal : $management;
            $items = array_values(array_filter($items, static function(array $item) use ($allow): bool {
                return in_array((string)($item['action'] ?? ''), $allow, true);
            }));
        }
        return array_slice($items, 0, bms_workspace_action_service::LIMIT);
    }
}
