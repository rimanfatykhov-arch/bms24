<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * RC29/1802: read-only management-cycle consistency audit.
 *
 * This class never discovers objects or broadens access. The caller must pass
 * read models already authorized by the canonical company-scoped providers.
 * It reports consistency, not causal business performance or test acceptance.
 */
final class management_cycle_acceptance_service {
    public const CONTRACT_VERSION = '1.0.0';

    private static function canonical_link(string $url, string $companyuuid): bool {
        global $CFG;
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }
        $path = $parts['path'] ?? null;
        $query = $parts['query'] ?? null;
        if (!is_string($path) || !is_string($query) ||
                !str_starts_with($path, '/local/kbacompany/')) {
            return false;
        }
        // Reject path traversal, alternate slash encodings, and forged hosts.
        $decoded = rawurldecode($path);
        if (str_contains($decoded, "\\") ||
                preg_match('~(^|/)\.{1,2}(/|$)~', $decoded) ||
                str_contains($decoded, '//')) {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        if (isset($parts['host']) || isset($parts['scheme']) || isset($parts['port'])) {
            // Moodle URLs may be absolute. An absolute link is only valid on the
            // *configured* Moodle origin, never on an arbitrary third-party host.
            if (empty($CFG->wwwroot)) {
                return false;
            }
            $site = parse_url((string)$CFG->wwwroot);
            if (!is_array($site) || !isset($site['host'], $site['scheme'], $parts['host'], $parts['scheme']) ||
                    strtolower((string)$parts['host']) !== strtolower((string)$site['host']) ||
                    strtolower((string)$parts['scheme']) !== strtolower((string)$site['scheme']) ||
                    (int)($parts['port'] ?? 0) !== (int)($site['port'] ?? 0)) {
                return false;
            }
        }
        parse_str($query, $params);
        return isset($params['uuid']) && is_string($params['uuid']) &&
            hash_equals($companyuuid, $params['uuid']);
    }

    /**
     * Inspect authorized provider projections, with NO database access.
     * Errors mean consistency or authorization provenance cannot be verified.
     * Warnings mean missing evidence and should not be mistaken for failures.
     */
    public static function inspect(string $companyuuid, array $base, array $dashboard): array {
        $errors = [];
        $warnings = [];
        $counts = ['plans'=>0, 'decision_loops'=>0, 'closed_loops'=>0,
            'awaiting_action'=>0, 'awaiting_fact'=>0, 'repeat_required'=>0,
            'restricted_tasks'=>0, 'dashboard_links'=>0];
        $week = (string)($base['week_start'] ?? '');
        $end = (string)($base['week_end'] ?? '');
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $week, new \DateTimeZone('UTC'));
        if (!$parsed || $parsed->format('Y-m-d') !== $week ||
                (int)$parsed->format('N') !== 1 ||
                $parsed->modify('+6 days')->format('Y-m-d') !== $end) {
            $errors[] = 'invalid_management_week';
        }
        foreach (['acceptance', 'overview'] as $source) {
            $sourceweek = (string)($base[$source]['week_start'] ?? '');
            if ($sourceweek !== $week) {
                $errors[] = 'week_mismatch_' . $source;
            }
        }
        if ((string)($dashboard['week_start'] ?? '') !== $week ||
                (string)($dashboard['week_end'] ?? '') !== $end) {
            $errors[] = 'week_mismatch_dashboard';
        }
        foreach (['needs_decision', 'critical_exceptions', 'active_plans'] as $key) {
            if ((int)($base['summary'][$key] ?? -1) !==
                    (int)($dashboard['totals'][$key] ?? -2)) {
                $errors[] = 'dashboard_counter_mismatch_' . $key;
            }
        }
        $queuesize = count((array)($base['overview']['review_queue'] ?? []));
        if ((int)($dashboard['totals']['approval_queue'] ?? -1) !== $queuesize) {
            $errors[] = 'approval_queue_count_mismatch';
        }
        if (empty($dashboard['protocol_available']) &&
                (($dashboard['totals']['protocol_open'] ?? null) !== null ||
                 ($dashboard['totals']['protocol_overdue'] ?? null) !== null)) {
            $errors[] = 'restricted_protocol_disclosed_as_count';
        }
        $counts['plans'] = (int)($base['summary']['active_plans'] ?? 0);
        foreach ((array)($base['decision_loops'] ?? []) as $loop) {
            $counts['decision_loops']++;
            $stage = (string)($loop['stage'] ?? '');
            if (!in_array($stage, ['decision', 'action', 'execution', 'control', 'followup', 'repeat', 'closed'], true)) {
                $errors[] = 'invalid_management_stage';
                continue;
            }
            if (!empty($loop['task_exists']) && empty($loop['task_visible'])) {
                $counts['restricted_tasks']++;
                if (!empty($loop['task_url'])) {
                    $errors[] = 'restricted_task_link_exposed';
                }
            }
            if ($stage === 'closed') {
                $counts['closed_loops']++;
                if (empty($loop['task_exists']) || (string)($loop['task_status'] ?? '') !== 'done' ||
                        (string)($loop['followup_quality'] ?? '') === 'insufficient' ||
                        !empty($loop['requires_new_decision'])) {
                    $warnings[] = 'closed_loop_requires_review';
                }
            } else if ($stage === 'action') {
                $counts['awaiting_action']++;
            } else if ($stage === 'followup') {
                $counts['awaiting_fact']++;
            } else if ($stage === 'repeat') {
                $counts['repeat_required']++;
            }
        }
        foreach ((array)($dashboard['items'] ?? []) as $item) {
            $url = (string)($item['url'] ?? '');
            if ($url === '') {
                $warnings[] = 'dashboard_object_without_link';
                continue;
            }
            $counts['dashboard_links']++;
            if (!self::canonical_link($url, $companyuuid)) {
                $errors[] = 'dashboard_link_outside_company';
            }
        }
        // Only distinct signal names are needed; never include client or employee data.
        $errors = array_values(array_unique($errors));
        $warnings = array_values(array_unique($warnings));
        return [
            'contract' => self::CONTRACT_VERSION,
            'week' => $week,
            'status' => $errors ? 'HOLD' : 'CONSISTENT_READ_MODELS',
            'errors' => $errors,
            'warnings' => $warnings,
            'counts' => $counts,
            'live_transactions_tested' => false,
        ];
    }
}
