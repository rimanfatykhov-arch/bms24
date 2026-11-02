<?php
// RC29/1806: pure dependency policy for visible workspace cards.
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Maps user-approved widget IDs to expensive read-model dependencies.
 * A null value retains the full historical model for other callers.
 * This policy never grants permissions; ACL is enforced by the services.
 */
final class bms_workspace_load_policy {
    public const CONTRACT_VERSION = '1.0.0';

    public static function requirements(?array $visible, bool $includeattentionmanagement = true): array {
        $legacy = $visible === null;
        $visible = $legacy ? bms_workspace_widget_service::DEFAULT_ORDER :
            array_values(array_intersect(bms_workspace_widget_service::DEFAULT_ORDER, $visible));
        $has = static function(string $key) use ($visible): bool {
            return in_array($key, $visible, true);
        };
        $any = static function(array $keys) use ($visible): bool {
            return (bool)array_intersect($keys, $visible);
        };
        // Attention uses both communications and (for managers) decision projections.
        $work = $any(['tasks', 'overview', 'attention']);
        return [
            'organization' => $any(['architecture', 'company']),
            'processes' => $has('architecture'),
            'work' => $work,
            'learning' => $has('overview'),
            'management' => $has('management') || ($includeattentionmanagement && $has('attention')),
            'legacy' => $legacy,
        ];
    }
}
