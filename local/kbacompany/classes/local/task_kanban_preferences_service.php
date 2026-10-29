<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * build1846: per-user, per-company Kanban presentation configuration.
 * No task fields/statuses are written, no new task workflow is introduced.
 */
final class task_kanban_preferences_service {
    /** Stable slots and allowed real task statuses; deleted tasks are never displayed. */
    public const STATUSES = ['open', 'doing', 'review', 'returned', 'done', 'cancelled'];
    public const GROUPS = ['none', 'assignee', 'project'];
    public const MAX_WIP_LIMIT = 100;

    private static function key(string $companyuuid): string {
        return 'local_kbacompany_kanban_' . $companyuuid;
    }

    public static function defaults(): array {
        $columns = [];
        $mapping = [];
        foreach (self::STATUSES as $position => $status) {
            $columns[$status] = ['label'=>'', 'visible'=>true, 'order'=>$position + 1];
            $mapping[$status] = $status;
        }
        return ['columns'=>$columns, 'mapping'=>$mapping, 'groupby'=>'none',
            'limits'=>array_fill_keys(self::STATUSES, 0)];
    }

    /** Whitelist IDs, bound text length, normalize order, and keep every live status visible. */
    public static function normalise(array $stored): array {
        $defaults = self::defaults();
        $input = isset($stored['columns']) && is_array($stored['columns']) ? $stored['columns'] : [];
        $columns = [];
        foreach (self::STATUSES as $i => $id) {
            $requested = isset($input[$id]) && is_array($input[$id]) ? $input[$id] : [];
            // Saved settings can be stale or tampered with: reject non-scalar
            // labels rather than producing an Array-to-string warning.
            $rawlabel = $requested['label'] ?? '';
            $label = is_string($rawlabel) ? trim(strip_tags($rawlabel)) : '';
            $label = \core_text::substr($label, 0, 60);
            // Empty custom names mean the localized canonical state label in the UI.
            $position = filter_var($requested['order'] ?? ($i+1), FILTER_VALIDATE_INT);
            $position = $position !== false ? min(6, max(1, $position)) : $i+1;
            $columns[$id] = [
                'label'=>$label,
                'visible'=>isset($requested['visible']) ? (bool)$requested['visible'] : true,
                'order'=>$position,
            ];
        }
        // Order is stable even with duplicate or invalid posted ordinal values.
        $ids = self::STATUSES;
        usort($ids, static function(string $a, string $b) use ($columns): int {
            $result = $columns[$a]['order'] <=> $columns[$b]['order'];
            return $result !== 0 ? $result : array_search($a, self::STATUSES, true) <=> array_search($b, self::STATUSES, true);
        });
        $ordered = [];
        foreach ($ids as $i => $id) {
            $columns[$id]['order'] = $i + 1;
            $ordered[$id] = $columns[$id];
        }
        if (!array_filter($ordered, static function(array $column): bool { return $column['visible']; })) {
            // Never return an empty Kanban where all real work is invisible.
            $first = array_key_first($ordered);
            $ordered[$first]['visible'] = true;
        }
        $firstvisible = '';
        foreach ($ordered as $id=>$column) {
            if ($column['visible']) { $firstvisible = $id; break; }
        }
        $requestedmapping = isset($stored['mapping']) && is_array($stored['mapping']) ? $stored['mapping'] : [];
        $mapping = [];
        foreach (self::STATUSES as $status) {
            $rawtarget = $requestedmapping[$status] ?? $defaults['mapping'][$status];
            $target = is_string($rawtarget) ? $rawtarget : $defaults['mapping'][$status];
            if (!in_array($target, self::STATUSES, true) || !$ordered[$target]['visible']) {
                $target = $firstvisible;
            }
            $mapping[$status] = $target;
        }
        $rawgroupby = $stored['groupby'] ?? 'none';
        $groupby = is_string($rawgroupby) ? $rawgroupby : 'none';
        if (!in_array($groupby, self::GROUPS, true)) { $groupby = 'none'; }
        $limits = [];
        $inputlimits = isset($stored['limits']) && is_array($stored['limits']) ? $stored['limits'] : [];
        foreach (self::STATUSES as $status) {
            $number = filter_var($inputlimits[$status] ?? 0, FILTER_VALIDATE_INT);
            $limits[$status] = $number === false ? 0 : max(0, min(self::MAX_WIP_LIMIT, $number));
        }
        return ['columns'=>$ordered, 'mapping'=>$mapping, 'groupby'=>$groupby, 'limits'=>$limits];
    }

    public static function current(string $companyuuid, int $actor): array {
        $raw = (string)get_user_preferences(self::key($companyuuid), '', $actor);
        $decoded = json_decode($raw, true);
        // Do not pass an empty object into normalise: preserve canonical default columns.
        return is_array($decoded) ? self::normalise($decoded) : self::defaults();
    }

    /** Only an unambiguous target status may request a native workflow transition. */
    public static function target_status(string $column, array $prefs): string {
        $statuses = [];
        foreach (self::STATUSES as $status) {
            if (($prefs['mapping'][$status] ?? '') === $column) { $statuses[] = $status; }
        }
        return count($statuses) === 1 ? $statuses[0] : '';
    }

    /** This is navigation to the canonical confirmation form, not a state mutation. */
    public static function drop_operation(string $from, string $to, array $actions,
            bool $hasreviewer = true): string {
        if ($from === $to || !in_array($from, self::STATUSES, true) ||
                !in_array($to, self::STATUSES, true)) { return ''; }
        $operations = [
            'doing' => ['open'=>'start', 'returned'=>'start'],
            'review' => ['open'=>'submit', 'doing'=>'submit', 'returned'=>'submit'],
            'done' => ['review'=>'accept'],
            'returned' => ['review'=>'return'],
            'cancelled' => ['open'=>'cancel', 'doing'=>'cancel', 'review'=>'cancel', 'returned'=>'cancel'],
        ];
        $operation = $operations[$to][$from] ?? '';
        if (!$hasreviewer && $to === 'review') { $operation = ''; }
        if (!$hasreviewer && $to === 'done' && in_array($from, ['open', 'doing', 'returned'], true)) {
            $operation = 'submit';
        }
        return $operation !== '' && !empty($actions[$operation]) ? $operation : '';
    }

    public static function save(string $companyuuid, int $actor, array $columns, array $mapping,
            string $groupby = 'none', array $limits = []): void {
        // Presence, not a forged checkbox value, determines visible columns.
        $safe = self::normalise(['columns'=>$columns, 'mapping'=>$mapping,
            'groupby'=>$groupby, 'limits'=>$limits]);
        set_user_preference(self::key($companyuuid),
            json_encode($safe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $actor);
    }
}
