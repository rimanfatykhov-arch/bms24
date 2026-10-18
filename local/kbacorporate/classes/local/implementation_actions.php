<?php
namespace local_kbacorporate\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Builds managed implementation actions from confirmed BMS audit gaps.
 *
 * The class intentionally reuses local_kbacorp_impl_task. It adds a safe,
 * idempotent bulk-provisioning layer without creating a parallel task store.
 */
final class implementation_actions {
    /** Default delivery windows by action priority. */
    private const PRIORITY_DAYS = [
        'critical' => 30,
        'core' => 60,
        'additional' => 90,
    ];

    /**
     * Calculate a default due date at the end of the selected delivery window.
     *
     * @param string $priority Action priority.
     * @param int $startdate Start timestamp.
     * @return int Due timestamp.
     */
    public static function default_due_date(string $priority, int $startdate): int {
        $days = self::PRIORITY_DAYS[$priority] ?? self::PRIORITY_DAYS['core'];
        return $startdate + ($days * DAYSECS);
    }

    /**
     * Create a deterministic action title from an audit item snapshot.
     *
     * @param \stdClass $item Audit item.
     * @return string
     */
    public static function default_title(\stdClass $item): string {
        return get_string('implementationactiontitledefault', 'local_kbacorporate',
            clean_param((string)$item->criterionname, PARAM_TEXT));
    }

    /**
     * Create a human-readable expected result.
     *
     * @param \stdClass $item Audit item.
     * @param \stdClass|null $route Selected development route.
     * @return string
     */
    public static function default_expected_result(\stdClass $item, ?\stdClass $route = null): string {
        if ($route && trim((string)($route->expectedresult ?? '')) !== '') {
            $result = trim((string)$route->expectedresult);
            if (trim((string)($route->evidencecriteria ?? '')) !== '') {
                $result .= ' ' . get_string('implementationactionevidenceexpected', 'local_kbacorporate',
                    trim((string)$route->evidencecriteria));
            }
            return $result;
        }
        if ($route && trim((string)($route->note ?? '')) !== '') {
            return trim((string)$route->note);
        }

        $parts = [];
        if ((int)$item->targetscore >= 0) {
            $parts[] = get_string('implementationactiontargetresult', 'local_kbacorporate', (object)[
                'criterion' => clean_param((string)$item->criterionname, PARAM_TEXT),
                'target' => (int)$item->targetscore,
            ]);
        } else {
            $parts[] = get_string('implementationactiontargetgeneric', 'local_kbacorporate',
                clean_param((string)$item->criterionname, PARAM_TEXT));
        }
        if (trim((string)($item->evidencehint ?? '')) !== '') {
            $parts[] = get_string('implementationactionevidenceexpected', 'local_kbacorporate',
                trim((string)$item->evidencehint));
        }
        return implode(' ', $parts);
    }

    /**
     * Return the preferred active route for a criterion.
     *
     * @param array $routes Active routes.
     * @return \stdClass|null
     */
    public static function preferred_route(array $routes): ?\stdClass {
        $routes = array_values(array_filter($routes, static function($route): bool {
            return (int)($route->actiontoolid ?? 0) > 0;
        }));
        if (!$routes) {
            return null;
        }
        $rank = ['critical' => 0, 'core' => 1, 'additional' => 2];
        usort($routes, static function($left, $right) use ($rank): int {
            $leftpriority = $rank[(string)($left->priority ?? '')] ?? 9;
            $rightpriority = $rank[(string)($right->priority ?? '')] ?? 9;
            return [$leftpriority, (int)$left->id] <=> [$rightpriority, (int)$right->id];
        });
        return reset($routes) ?: null;
    }

    /**
     * Build the audit-to-action preview.
     *
     * @param int $companyid Company id.
     * @param int $auditid Audit id.
     * @param int $now Optional reference timestamp for deterministic tests.
     * @return \stdClass
     */
    public static function preview(int $companyid, int $auditid, int $now = 0): \stdClass {
        global $DB;

        $audit = implementation_audits::get_audit($auditid);
        if ((int)$audit->companyid !== $companyid) {
            throw new \moodle_exception('companynotallowed', 'local_kbacorporate');
        }
        $now = $now > 0 ? $now : time();
        $startdate = usergetmidnight($now);

        $existingbyitem = [];
        foreach ($DB->get_records('local_kbacorp_impl_task', [
            'companyid' => $companyid,
            'auditid' => $auditid,
            'deleted' => 0,
        ], 'id ASC') as $task) {
            $itemid = (int)$task->itemid;
            if (!isset($existingbyitem[$itemid]) ||
                    ((string)$existingbyitem[$itemid]->status === implementation_audits::TASK_CANCELLED &&
                    (string)$task->status !== implementation_audits::TASK_CANCELLED)) {
                $existingbyitem[$itemid] = $task;
            }
        }

        $rows = [];
        $counts = (object)[
            'gaps' => 0,
            'critical' => 0,
            'withactions' => 0,
            'withoutactions' => 0,
            'withroutes' => 0,
            'withoutroutes' => 0,
        ];

        foreach (implementation_audits::get_gap_rows($auditid) as $item) {
            if ($item->gap === null || (int)$item->gap <= 0) {
                continue;
            }
            $priority = in_array((string)$item->gappriority,
                ['critical', 'core', 'additional'], true) ? (string)$item->gappriority : 'core';
            $routes = array_values($item->routes ?? []);
            $preferredroute = self::preferred_route($routes);
            $existing = $existingbyitem[(int)$item->id] ?? null;

            $row = clone $item;
            $row->priority = $priority;
            $row->routes = $routes;
            $row->preferredroute = $preferredroute;
            $row->existingtask = $existing;
            $row->startdate = $startdate;
            $row->duedate = self::default_due_date($priority, $startdate);
            $row->actiontitle = self::default_title($item);
            $row->expectedresult = self::default_expected_result($item, $preferredroute);
            $row->eligible = $existing === null;
            $rows[] = $row;

            $counts->gaps++;
            if ($priority === 'critical') {
                $counts->critical++;
            }
            if ($existing) {
                $counts->withactions++;
            } else {
                $counts->withoutactions++;
            }
            if ($preferredroute) {
                $counts->withroutes++;
            } else {
                $counts->withoutroutes++;
            }
        }

        return (object)[
            'audit' => $audit,
            'rows' => $rows,
            'counts' => $counts,
            'startdate' => $startdate,
        ];
    }

    /**
     * Create selected actions. Existing tasks are never duplicated or silently overwritten.
     *
     * @param int $companyid Company id.
     * @param int $auditid Audit id.
     * @param array $itemids Selected audit item ids.
     * @param array $routeids Route id keyed by audit item id.
     * @param int $ownerid Common owner id, zero means unassigned.
     * @param int $actorid User creating the actions.
     * @param int $now Optional reference timestamp for deterministic tests.
     * @return \stdClass
     */
    public static function create_selected(int $companyid, int $auditid, array $itemids,
            array $routeids, int $ownerid, int $actorid, int $now = 0): \stdClass {
        global $DB;

        $audit = implementation_audits::get_audit($auditid);
        if ((int)$audit->companyid !== $companyid) {
            throw new \moodle_exception('companynotallowed', 'local_kbacorporate');
        }
        if ((string)$audit->status !== implementation_audits::STATUS_CONFIRMED) {
            throw new \moodle_exception('implementationactionauditnotconfirmed', 'local_kbacorporate');
        }
        $program = implementation_programs::get_for_audit($auditid);
        if (!implementation_programs::plan_editable($program)) {
            throw new \moodle_exception('implementationprogramnotactive', 'local_kbacorporate');
        }
        if ($ownerid > 0 && !$DB->record_exists('local_kbacorp_member', [
                'companyid' => $companyid,
                'userid' => $ownerid,
                'isemployee' => 1,
            ])) {
            throw new \moodle_exception('implementationtaskownerinvalid', 'local_kbacorporate');
        }

        $itemids = array_values(array_unique(array_filter(array_map('intval', $itemids))));
        $now = $now > 0 ? $now : time();
        $startdate = usergetmidnight($now);
        $result = (object)[
            'selected' => count($itemids),
            'created' => 0,
            'existing' => 0,
            'ineligible' => 0,
            'errors' => 0,
            'taskids' => [],
        ];

        foreach ($itemids as $itemid) {
            try {
                $item = $DB->get_record('local_kbacorp_impl_item', [
                    'id' => $itemid,
                    'auditid' => $auditid,
                ], '*', MUST_EXIST);

                $assessed = (int)$item->score >= 0 && !in_array((string)$item->evidencestatus, [
                    implementation_audits::EVIDENCE_MISSING,
                    implementation_audits::EVIDENCE_NEEDS_DATA,
                    implementation_audits::EVIDENCE_NOT_APPLICABLE,
                ], true);
                $gap = $assessed && (int)$item->targetscore >= 0
                    ? max(0, (int)$item->targetscore - (int)$item->score) : null;
                if ($gap === null || $gap <= 0) {
                    $result->ineligible++;
                    continue;
                }

                if ($DB->record_exists('local_kbacorp_impl_task', [
                        'companyid' => $companyid,
                        'auditid' => $auditid,
                        'itemid' => $itemid,
                        'deleted' => 0,
                    ])) {
                    $result->existing++;
                    continue;
                }

                $routeid = isset($routeids[$itemid]) ? (int)$routeids[$itemid] : 0;
                $route = null;
                if ($routeid > 0) {
                    $route = $DB->get_record('local_kbacorp_bms_route', [
                        'id' => $routeid,
                        'companyid' => $companyid,
                        'criterionid' => (int)$item->criterionid,
                        'active' => 1,
                    ], '*', MUST_EXIST);
                    if ((int)$route->actiontoolid <= 0) {
                        throw new \moodle_exception('implementationroutelegacyincomplete',
                            'local_kbacorporate');
                    }
                } else {
                    $route = self::preferred_route(
                        implementation_audits::get_routes((int)$item->criterionid, true));
                    $routeid = $route ? (int)$route->id : 0;
                }

                $priority = implementation_audits::gap_priority($gap);
                if (!in_array($priority, ['critical', 'core', 'additional'], true)) {
                    $priority = 'core';
                }
                $data = (object)[
                    'companyid' => $companyid,
                    'auditid' => $auditid,
                    'itemid' => $itemid,
                    'routeid' => $routeid,
                    'title' => self::default_title($item),
                    'ownerid' => $ownerid,
                    'priority' => $priority,
                    'status' => implementation_audits::TASK_PLANNED,
                    'expectedresult' => self::default_expected_result($item, $route),
                    'completioncomment' => '',
                    'resulturl' => '',
                    'startdate' => $startdate,
                    'duedate' => self::default_due_date($priority, $startdate),
                ];
                $taskid = implementation_audits::save_task($data, $actorid);
                $result->created++;
                $result->taskids[] = $taskid;
            } catch (\Throwable $exception) {
                debugging('Audit-to-action creation failed for item ' . $itemid . ': ' .
                    $exception->getMessage(), DEBUG_DEVELOPER);
                $result->errors++;
            }
        }

        return $result;
    }
}
