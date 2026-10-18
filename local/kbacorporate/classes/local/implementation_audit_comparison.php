<?php
// This file is part of Moodle - http://moodle.org/

namespace local_kbacorporate\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Read-only comparison of two confirmed BMS implementation audits.
 *
 * Criteria are matched by the immutable criterion code stored in each audit
 * item rather than by the current database criterion id. This keeps the
 * comparison valid when the BMS tool was published as a new version between
 * the two audits.
 */
final class implementation_audit_comparison {
    public const STATUS_IMPROVED = 'improved';
    public const STATUS_WORSENED = 'worsened';
    public const STATUS_UNCHANGED = 'unchanged';
    public const STATUS_NEWLY_ASSESSED = 'newlyassessed';
    public const STATUS_LOST_ASSESSMENT = 'lostassessment';
    public const STATUS_ADDED = 'added';
    public const STATUS_REMOVED = 'removed';
    public const STATUS_UNASSESSED = 'unassessed';

    public const RISK_NONE = 'none';
    public const RISK_NEW = 'new';
    public const RISK_RESOLVED = 'resolved';
    public const RISK_PERSISTENT = 'persistent';
    public const RISK_UNKNOWN = 'unknown';

    /** Reuse the same company-access projection as the section progress screen. */
    public static function get_accessible_companies(int $userid, bool $canmanage): array {
        return implementation_section_progress::get_accessible_companies($userid, $canmanage);
    }

    /** Whether the viewer may open comparison data for one company. */
    public static function can_view_company(int $companyid, int $userid, bool $canmanage): bool {
        return implementation_section_progress::can_view_company($companyid, $userid, $canmanage);
    }

    /** Tools with at least two confirmed audits in one company. */
    public static function get_tools_with_history(int $companyid): array {
        global $DB;

        return array_values($DB->get_records_sql(
            "SELECT t.id, t.code, t.name, COUNT(a.id) AS auditcount,
                    MAX(a.auditdate) AS latestauditdate
               FROM {local_kbacorp_impl_audit} a
               JOIN {local_kbacorp_bms_tool} t ON t.id = a.toolid
              WHERE a.companyid = :companyid
                AND a.status = :status
           GROUP BY t.id, t.code, t.name
             HAVING COUNT(a.id) >= 2
           ORDER BY latestauditdate DESC, t.name ASC",
            [
                'companyid' => $companyid,
                'status' => implementation_audits::STATUS_CONFIRMED,
            ]
        ));
    }

    /** Confirmed audits for one company and optional tool, newest first. */
    public static function get_confirmed_audits(int $companyid, int $toolid = 0): array {
        global $DB;

        $where = [
            'a.companyid = :companyid',
            'a.status = :status',
        ];
        $params = [
            'companyid' => $companyid,
            'status' => implementation_audits::STATUS_CONFIRMED,
        ];
        if ($toolid > 0) {
            $where[] = 'a.toolid = :toolid';
            $params['toolid'] = $toolid;
        }

        return array_values($DB->get_records_sql(
            "SELECT a.*, t.name AS toolname, t.code AS toolcode
               FROM {local_kbacorp_impl_audit} a
               JOIN {local_kbacorp_bms_tool} t ON t.id = a.toolid
              WHERE " . implode(' AND ', $where) . "
           ORDER BY a.auditdate DESC, a.id DESC",
            $params
        ));
    }

    /**
     * Select a useful default pair from audits already filtered to one tool.
     *
     * @return \stdClass|null Object with before and after audit records.
     */
    public static function default_pair(array $audits, int $preferredafterid = 0): ?\stdClass {
        if (count($audits) < 2) {
            return null;
        }
        $audits = array_values($audits);
        if ($preferredafterid > 0) {
            foreach ($audits as $index => $audit) {
                if ((int)$audit->id !== $preferredafterid) {
                    continue;
                }
                // The list is newest first, therefore the next element is the
                // nearest preceding confirmed audit.
                if (isset($audits[$index + 1])) {
                    return (object)[
                        'before' => $audits[$index + 1],
                        'after' => $audit,
                    ];
                }
                break;
            }
        }
        return (object)[
            'before' => $audits[1],
            'after' => $audits[0],
        ];
    }

    /** Build the complete comparison model and enforce company/tool isolation. */
    public static function build(int $companyid, int $beforeid, int $afterid,
            bool $includeeconomic = true): \stdClass {
        $before = implementation_audits::get_audit($beforeid);
        $after = implementation_audits::get_audit($afterid);
        self::validate_pair($companyid, $before, $after);

        // Always keep the comparison chronological, even if the GET form was
        // submitted with the two values reversed.
        if (self::audit_sort_key($before) > self::audit_sort_key($after)) {
            $swap = $before;
            $before = $after;
            $after = $swap;
            $swapid = $beforeid;
            $beforeid = $afterid;
            $afterid = $swapid;
        }

        $tasks = self::get_baseline_tasks($companyid, $beforeid, (int)$after->auditdate);
        $evidence = self::get_accepted_evidence_between(
            array_map(static function($task) {
                return (int)$task->id;
            }, $tasks),
            (int)$before->auditdate,
            (int)$after->auditdate
        );

        $beforeeconomic = [];
        $aftereconomic = [];
        if ($includeeconomic) {
            $beforeeconomic = economic_indicators::get_values($companyid, [
                'auditid' => $beforeid,
                'scenario' => economic_indicators::SCENARIO_ACTUAL,
            ]);
            $aftereconomic = economic_indicators::get_values($companyid, [
                'auditid' => $afterid,
                'scenario' => economic_indicators::SCENARIO_ACTUAL,
            ]);
        }

        $model = self::aggregate_records(
            $before,
            $after,
            implementation_audits::get_items($beforeid),
            implementation_audits::get_items($afterid),
            $tasks,
            $evidence,
            $beforeeconomic,
            $aftereconomic
        );
        $model->beforeaudit = $before;
        $model->afteraudit = $after;
        return $model;
    }

    /** Validate that two audit snapshots are comparable. */
    private static function validate_pair(int $companyid, \stdClass $before, \stdClass $after): void {
        if ((int)$before->companyid !== $companyid || (int)$after->companyid !== $companyid) {
            throw new \moodle_exception('companynotallowed', 'local_kbacorporate');
        }
        if ((int)$before->id === (int)$after->id) {
            throw new \moodle_exception('auditcomparisonsameaudit', 'local_kbacorporate');
        }
        if ((string)$before->status !== implementation_audits::STATUS_CONFIRMED ||
                (string)$after->status !== implementation_audits::STATUS_CONFIRMED) {
            throw new \moodle_exception('auditcomparisonconfirmedonly', 'local_kbacorporate');
        }
        if ((int)$before->toolid !== (int)$after->toolid) {
            throw new \moodle_exception('auditcomparisondifferenttools', 'local_kbacorporate');
        }
    }

    /** A stable chronological key using date first and audit id as a tie-breaker. */
    private static function audit_sort_key(\stdClass $audit): string {
        return sprintf('%012d:%012d', (int)$audit->auditdate, (int)$audit->id);
    }

    /** Tasks generated from the earlier audit, with immutable criterion codes. */
    private static function get_baseline_tasks(int $companyid, int $auditid, int $todate): array {
        global $DB;
        if (!$DB->get_manager()->table_exists(new \xmldb_table('local_kbacorp_impl_task'))) {
            return [];
        }
        return array_values($DB->get_records_sql(
            "SELECT t.*, i.criterioncode, i.criterionname, i.sectioncode, i.sectionname,
                    u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic,
                    u.middlename, u.alternatename
               FROM {local_kbacorp_impl_task} t
               JOIN {local_kbacorp_impl_item} i ON i.id = t.itemid
          LEFT JOIN {user} u ON u.id = t.ownerid
              WHERE t.companyid = :companyid
                AND t.auditid = :auditid
                AND t.deleted = 0
                AND t.timecreated <= :todate
           ORDER BY t.timecreated ASC, t.id ASC",
            [
                'companyid' => $companyid,
                'auditid' => $auditid,
                'todate' => $todate + DAYSECS - 1,
            ]
        ));
    }

    /** Accepted evidence decisions recorded between the two audit dates. */
    private static function get_accepted_evidence_between(array $taskids, int $fromdate,
            int $todate): array {
        global $DB;
        if (!$taskids || !implementation_evidence::is_available()) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($taskids, SQL_PARAMS_NAMED, 'comparison_task');
        $start = min($fromdate, $todate);
        $end = max($fromdate, $todate) + DAYSECS - 1;
        $params['accepted'] = implementation_evidence::STATUS_ACCEPTED;
        $params['fromdate'] = $start;
        $params['todate'] = $end;
        return array_values($DB->get_records_sql(
            "SELECT e.*
               FROM {local_kbacorp_impl_evid} e
              WHERE e.taskid {$insql}
                AND e.status = :accepted
                AND e.reviewedat >= :fromdate
                AND e.reviewedat <= :todate
           ORDER BY e.reviewedat ASC, e.id ASC",
            $params
        ));
    }

    /**
     * Pure record-based comparison used by the UI and regression tests.
     *
     * @param \stdClass $beforeaudit Earlier confirmed audit.
     * @param \stdClass $afteraudit Later confirmed audit.
     * @param array $beforeitems Earlier audit items.
     * @param array $afteritems Later audit items.
     * @param array $tasks Tasks generated from the earlier audit.
     * @param array $acceptedevidence Accepted evidence decisions in the interval.
     * @param array $beforeeconomic Actual economic values linked to the earlier audit.
     * @param array $aftereconomic Actual economic values linked to the later audit.
     */
    public static function aggregate_records(\stdClass $beforeaudit, \stdClass $afteraudit,
            array $beforeitems, array $afteritems, array $tasks = [], array $acceptedevidence = [],
            array $beforeeconomic = [], array $aftereconomic = []): \stdClass {
        $beforemap = self::items_by_code($beforeitems);
        $aftermap = self::items_by_code($afteritems);
        $codes = array_values(array_unique(array_merge(array_keys($beforemap), array_keys($aftermap))));
        usort($codes, 'strnatcasecmp');

        $taskmap = [];
        foreach ($tasks as $task) {
            $code = trim((string)($task->criterioncode ?? ''));
            if ($code === '') {
                continue;
            }
            if (!isset($taskmap[$code])) {
                $taskmap[$code] = [];
            }
            $taskmap[$code][] = $task;
        }
        $acceptedevidencebytask = [];
        foreach ($acceptedevidence as $evidence) {
            $taskid = (int)($evidence->taskid ?? 0);
            if ($taskid > 0) {
                $acceptedevidencebytask[$taskid] = ($acceptedevidencebytask[$taskid] ?? 0) + 1;
            }
        }

        $summary = (object)[
            'totalcriteria' => count($codes),
            'matchedcriteria' => 0,
            'comparablecriteria' => 0,
            'improved' => 0,
            'worsened' => 0,
            'unchanged' => 0,
            'newlyassessed' => 0,
            'lostassessment' => 0,
            'added' => 0,
            'removed' => 0,
            'unassessed' => 0,
            'beforeattention' => 0,
            'afterattention' => 0,
            'newrisks' => 0,
            'resolvedrisks' => 0,
            'persistentrisks' => 0,
            'beforegaps' => 0,
            'aftergaps' => 0,
            'actions' => 0,
            'actionscreatedbetween' => 0,
            'actionscompletedbetween' => 0,
            'actionsopen' => 0,
            'actionscancelled' => 0,
            'acceptedevidence' => count($acceptedevidence),
            'economiccomparable' => 0,
            'economicpositive' => 0,
            'economicnegative' => 0,
        ];

        $criteria = [];
        $sectionmap = [];
        foreach ($codes as $code) {
            $before = $beforemap[$code] ?? null;
            $after = $aftermap[$code] ?? null;
            if ($before && $after) {
                $summary->matchedcriteria++;
            }

            $beforeassessed = $before ? self::item_is_assessed($before, $beforeaudit) : false;
            $afterassessed = $after ? self::item_is_assessed($after, $afteraudit) : false;
            $beforenormalized = $beforeassessed ? self::normalise_score((int)$before->score,
                (int)$beforeaudit->scalemax) : null;
            $afternormalized = $afterassessed ? self::normalise_score((int)$after->score,
                (int)$afteraudit->scalemax) : null;
            $normalizeddelta = $beforenormalized !== null && $afternormalized !== null
                ? round($afternormalized - $beforenormalized, 2) : null;
            $rawdelta = $normalizeddelta !== null && (int)$beforeaudit->scalemax === (int)$afteraudit->scalemax
                ? (int)$after->score - (int)$before->score : null;

            $status = self::comparison_status($before, $after, $beforeassessed, $afterassessed,
                $normalizeddelta);
            if (property_exists($summary, $status)) {
                $summary->{$status}++;
            }
            if ($beforeassessed && $afterassessed) {
                $summary->comparablecriteria++;
            }

            $beforerisk = $beforeassessed && (int)$before->score < (int)$beforeaudit->attentionthreshold;
            $afterrisk = $afterassessed && (int)$after->score < (int)$afteraudit->attentionthreshold;
            if ($beforerisk) {
                $summary->beforeattention++;
            }
            if ($afterrisk) {
                $summary->afterattention++;
            }
            $riskstatus = self::risk_status($beforerisk, $afterrisk, $afterassessed);
            if ($riskstatus === self::RISK_NEW) {
                $summary->newrisks++;
            } else if ($riskstatus === self::RISK_RESOLVED) {
                $summary->resolvedrisks++;
            } else if ($riskstatus === self::RISK_PERSISTENT) {
                $summary->persistentrisks++;
            }

            $beforegap = self::item_gap($before, $beforeassessed);
            $aftergap = self::item_gap($after, $afterassessed);
            if ($beforegap !== null && $beforegap > 0) {
                $summary->beforegaps++;
            }
            if ($aftergap !== null && $aftergap > 0) {
                $summary->aftergaps++;
            }

            $rowtasks = $taskmap[$code] ?? [];
            $acceptedcount = 0;
            foreach ($rowtasks as $task) {
                $task->acceptedevidencebetween = $acceptedevidencebytask[(int)$task->id] ?? 0;
                $acceptedcount += (int)$task->acceptedevidencebetween;
            }

            $sectioncode = trim((string)($after->sectioncode ?? ($before->sectioncode ?? '')));
            if ($sectioncode === '') {
                $sectioncode = 'general';
            }
            $sectionname = trim((string)($after->sectionname ?? ($before->sectionname ?? '')));

            $row = (object)[
                'criterioncode' => $code,
                'criterionname' => (string)($after->criterionname ?? ($before->criterionname ?? $code)),
                'sectioncode' => $sectioncode,
                'sectionname' => $sectionname,
                'beforeitem' => $before,
                'afteritem' => $after,
                'beforeassessed' => $beforeassessed,
                'afterassessed' => $afterassessed,
                'beforescore' => $beforeassessed ? (int)$before->score : null,
                'afterscore' => $afterassessed ? (int)$after->score : null,
                'beforenormalized' => $beforenormalized,
                'afternormalized' => $afternormalized,
                'rawdelta' => $rawdelta,
                'normalizeddelta' => $normalizeddelta,
                'beforetarget' => $before && (int)$before->targetscore >= 0 ? (int)$before->targetscore : null,
                'aftertarget' => $after && (int)$after->targetscore >= 0 ? (int)$after->targetscore : null,
                'beforegap' => $beforegap,
                'aftergap' => $aftergap,
                'gapchange' => $beforegap !== null && $aftergap !== null
                    ? $aftergap - $beforegap : null,
                'status' => $status,
                'beforerisk' => $beforerisk,
                'afterrisk' => $afterrisk,
                'riskstatus' => $riskstatus,
                'sectionchanged' => $before && $after &&
                    (string)($before->sectioncode ?? '') !== (string)($after->sectioncode ?? ''),
                'tasks' => $rowtasks,
                'acceptedevidence' => $acceptedcount,
            ];
            $criteria[] = $row;

            if (!isset($sectionmap[$sectioncode])) {
                $sectionmap[$sectioncode] = self::empty_section($sectioncode, $sectionname);
            }
            self::add_row_to_section($sectionmap[$sectioncode], $row);
        }

        foreach ($tasks as $task) {
            $status = (string)($task->status ?? '');
            if ($status === implementation_audits::TASK_CANCELLED) {
                $summary->actionscancelled++;
                continue;
            }
            $summary->actions++;
            if (self::time_in_interval((int)($task->timecreated ?? 0),
                    (int)$beforeaudit->auditdate, (int)$afteraudit->auditdate)) {
                $summary->actionscreatedbetween++;
            }
            if (self::time_in_interval((int)($task->completedat ?? 0),
                    (int)$beforeaudit->auditdate, (int)$afteraudit->auditdate)) {
                $summary->actionscompletedbetween++;
            }
            if (!in_array($status, [implementation_audits::TASK_COMPLETED,
                    implementation_audits::TASK_CANCELLED], true)) {
                $summary->actionsopen++;
            }
        }

        $sections = array_values($sectionmap);
        foreach ($sections as $section) {
            self::finalise_section($section);
        }
        usort($sections, static function($left, $right) {
            return strnatcasecmp((string)$left->code, (string)$right->code);
        });

        $economic = self::compare_economic_records($beforeeconomic, $aftereconomic);
        $summary->economiccomparable = $economic->summary->comparable;
        $summary->economicpositive = $economic->summary->positive;
        $summary->economicnegative = $economic->summary->negative;

        $beforeindex = $beforeaudit->indexvalue === null ? null : (float)$beforeaudit->indexvalue;
        $afterindex = $afteraudit->indexvalue === null ? null : (float)$afteraudit->indexvalue;
        $beforecoverage = isset($beforeaudit->coverage) ? (float)$beforeaudit->coverage : null;
        $aftercoverage = isset($afteraudit->coverage) ? (float)$afteraudit->coverage : null;

        return (object)[
            'summary' => $summary,
            'criteria' => $criteria,
            'sections' => $sections,
            'tasks' => array_values($tasks),
            'acceptedevidence' => array_values($acceptedevidence),
            'economic' => $economic,
            'beforeindex' => $beforeindex,
            'afterindex' => $afterindex,
            'indexdelta' => $beforeindex !== null && $afterindex !== null
                ? round($afterindex - $beforeindex, 2) : null,
            'beforecoverage' => $beforecoverage,
            'aftercoverage' => $aftercoverage,
            'coveragedelta' => $beforecoverage !== null && $aftercoverage !== null
                ? round($aftercoverage - $beforecoverage, 2) : null,
        ];
    }

    /** Apply criterion filters without changing the comparison model. */
    public static function filter_criteria(array $criteria, array $filters): array {
        $status = trim((string)($filters['status'] ?? ''));
        $risk = trim((string)($filters['risk'] ?? ''));
        $section = trim((string)($filters['section'] ?? ''));
        $query = \core_text::strtolower(trim((string)($filters['query'] ?? '')));

        return array_values(array_filter($criteria, static function($row) use ($status, $risk, $section, $query) {
            if ($status !== '' && (string)$row->status !== $status) {
                return false;
            }
            if ($risk !== '' && (string)$row->riskstatus !== $risk) {
                return false;
            }
            if ($section !== '' && (string)$row->sectioncode !== $section) {
                return false;
            }
            if ($query !== '') {
                $haystack = \core_text::strtolower((string)$row->criterioncode . ' ' .
                    (string)$row->criterionname . ' ' . (string)$row->sectionname);
                if (strpos($haystack, $query) === false) {
                    return false;
                }
            }
            return true;
        }));
    }

    /** Build one row per economic indicator code, keeping the latest linked value per audit. */
    public static function compare_economic_records(array $beforevalues, array $aftervalues): \stdClass {
        $beforemap = self::latest_economic_by_code($beforevalues);
        $aftermap = self::latest_economic_by_code($aftervalues);
        $codes = array_values(array_unique(array_merge(array_keys($beforemap), array_keys($aftermap))));
        usort($codes, 'strnatcasecmp');
        $summary = (object)['total' => count($codes), 'comparable' => 0, 'positive' => 0,
            'negative' => 0, 'neutral' => 0, 'incomparable' => 0];
        $rows = [];

        foreach ($codes as $code) {
            $before = $beforemap[$code] ?? null;
            $after = $aftermap[$code] ?? null;
            $valuetype = (string)($after->valuetype ?? ($before->valuetype ?? 'ratio'));
            $unit = (string)($after->unit ?? ($before->unit ?? ''));
            $direction = (string)($after->direction ?? ($before->direction ?? economic_indicators::DIRECTION_TARGET));
            $currencycompatible = !$before || !$after || $valuetype !== economic_indicators::TYPE_MONEY ||
                (string)$before->currency === (string)$after->currency;
            $delta = null;
            $percentdelta = null;
            $outcome = 'incomparable';
            if ($before && $after && $currencycompatible) {
                $delta = round((float)$after->value - (float)$before->value, 6);
                if ((float)$before->value != 0.0) {
                    $percentdelta = round($delta / abs((float)$before->value) * 100, 2);
                }
                $summary->comparable++;
                if ($delta == 0.0 || $direction === economic_indicators::DIRECTION_TARGET) {
                    $outcome = 'neutral';
                    $summary->neutral++;
                } else {
                    $positive = ($direction === economic_indicators::DIRECTION_UP && $delta > 0) ||
                        ($direction === economic_indicators::DIRECTION_DOWN && $delta < 0);
                    $outcome = $positive ? 'positive' : 'negative';
                    $summary->{$outcome}++;
                }
            } else {
                $summary->incomparable++;
            }
            $rows[] = (object)[
                'indicatorcode' => $code,
                'indicatorname' => (string)($after->indicatorname ?? ($before->indicatorname ?? $code)),
                'category' => (string)($after->category ?? ($before->category ?? '')),
                'valuetype' => $valuetype,
                'unit' => $unit,
                'direction' => $direction,
                'before' => $before,
                'after' => $after,
                'delta' => $delta,
                'percentdelta' => $percentdelta,
                'outcome' => $outcome,
            ];
        }
        return (object)['rows' => $rows, 'summary' => $summary];
    }

    /** Map audit items by immutable criterion code. */
    private static function items_by_code(array $items): array {
        $map = [];
        foreach ($items as $item) {
            $code = trim((string)($item->criterioncode ?? ''));
            if ($code !== '' && !isset($map[$code])) {
                $map[$code] = $item;
            }
        }
        return $map;
    }

    /** Whether an item participates in the confirmed maturity score. */
    private static function item_is_assessed(\stdClass $item, \stdClass $audit): bool {
        if ((int)($item->score ?? -1) < 0) {
            return false;
        }
        $evidencestatus = (string)($item->evidencestatus ?? implementation_audits::EVIDENCE_MISSING);
        if ($evidencestatus === implementation_audits::EVIDENCE_NOT_APPLICABLE ||
                $evidencestatus === implementation_audits::EVIDENCE_NEEDS_DATA ||
                $evidencestatus === implementation_audits::EVIDENCE_MISSING) {
            return false;
        }
        if ((string)($audit->indexmethod ?? '') === implementation_audits::INDEX_WEIGHTED_VERIFIED) {
            return $evidencestatus === implementation_audits::EVIDENCE_VERIFIED;
        }
        return true;
    }

    /** Normalise a score to a percentage so different audit scales remain comparable. */
    private static function normalise_score(int $score, int $scalemax): float {
        return round($score / max(1, $scalemax) * 100, 2);
    }

    /** Determine one comparison status. */
    private static function comparison_status($before, $after, bool $beforeassessed,
            bool $afterassessed, ?float $delta): string {
        if (!$before) {
            return self::STATUS_ADDED;
        }
        if (!$after) {
            return self::STATUS_REMOVED;
        }
        if ($beforeassessed && $afterassessed) {
            if ($delta > 0.005) {
                return self::STATUS_IMPROVED;
            }
            if ($delta < -0.005) {
                return self::STATUS_WORSENED;
            }
            return self::STATUS_UNCHANGED;
        }
        if (!$beforeassessed && $afterassessed) {
            return self::STATUS_NEWLY_ASSESSED;
        }
        if ($beforeassessed && !$afterassessed) {
            return self::STATUS_LOST_ASSESSMENT;
        }
        return self::STATUS_UNASSESSED;
    }

    /** Determine how the attention-risk state changed. */
    private static function risk_status(bool $beforerisk, bool $afterrisk, bool $afterassessed): string {
        if ($beforerisk && $afterrisk) {
            return self::RISK_PERSISTENT;
        }
        if (!$beforerisk && $afterrisk) {
            return self::RISK_NEW;
        }
        if ($beforerisk && $afterassessed && !$afterrisk) {
            return self::RISK_RESOLVED;
        }
        if ($beforerisk && !$afterassessed) {
            return self::RISK_UNKNOWN;
        }
        return self::RISK_NONE;
    }

    /** Positive distance from the item's own target, or null when unknown. */
    private static function item_gap($item, bool $assessed): ?int {
        if (!$item || !$assessed || (int)($item->targetscore ?? -1) < 0) {
            return null;
        }
        return max(0, (int)$item->targetscore - (int)$item->score);
    }

    /** Initialise a section accumulator. */
    private static function empty_section(string $code, string $name): \stdClass {
        return (object)[
            'code' => $code,
            'name' => $name,
            'totalcriteria' => 0,
            'comparablecriteria' => 0,
            'beforecount' => 0,
            'aftercount' => 0,
            'beforenormalizedsum' => 0.0,
            'afternormalizedsum' => 0.0,
            'beforeaverage' => null,
            'afteraverage' => null,
            'change' => null,
            'improved' => 0,
            'worsened' => 0,
            'unchanged' => 0,
            'newlyassessed' => 0,
            'lostassessment' => 0,
            'newrisks' => 0,
            'resolvedrisks' => 0,
            'persistentrisks' => 0,
            'beforegaps' => 0,
            'aftergaps' => 0,
            'status' => 'unassessed',
        ];
    }

    /** Add one criterion result to a section accumulator. */
    private static function add_row_to_section(\stdClass $section, \stdClass $row): void {
        $section->totalcriteria++;
        if ($row->beforenormalized !== null) {
            $section->beforecount++;
            $section->beforenormalizedsum += (float)$row->beforenormalized;
        }
        if ($row->afternormalized !== null) {
            $section->aftercount++;
            $section->afternormalizedsum += (float)$row->afternormalized;
        }
        if ($row->beforeassessed && $row->afterassessed) {
            $section->comparablecriteria++;
        }
        if (property_exists($section, (string)$row->status)) {
            $field = (string)$row->status;
            $section->{$field}++;
        }
        if ($row->riskstatus === self::RISK_NEW) {
            $section->newrisks++;
        } else if ($row->riskstatus === self::RISK_RESOLVED) {
            $section->resolvedrisks++;
        } else if ($row->riskstatus === self::RISK_PERSISTENT) {
            $section->persistentrisks++;
        }
        if ($row->beforegap !== null && $row->beforegap > 0) {
            $section->beforegaps++;
        }
        if ($row->aftergap !== null && $row->aftergap > 0) {
            $section->aftergaps++;
        }
    }

    /** Complete section averages and status. */
    private static function finalise_section(\stdClass $section): void {
        $section->beforeaverage = $section->beforecount > 0
            ? round($section->beforenormalizedsum / $section->beforecount, 2) : null;
        $section->afteraverage = $section->aftercount > 0
            ? round($section->afternormalizedsum / $section->aftercount, 2) : null;
        $section->change = $section->beforeaverage !== null && $section->afteraverage !== null
            ? round($section->afteraverage - $section->beforeaverage, 2) : null;
        if ($section->newrisks > 0 || ($section->change !== null && $section->change < -0.005)) {
            $section->status = 'worsened';
        } else if ($section->change !== null && $section->change > 0.005) {
            $section->status = 'improved';
        } else if ($section->beforeaverage !== null && $section->afteraverage !== null) {
            $section->status = 'unchanged';
        } else if ($section->afteraverage !== null) {
            $section->status = 'newlyassessed';
        } else if ($section->beforeaverage !== null) {
            $section->status = 'lostassessment';
        }
    }

    /** Latest linked economic value per stable indicator code. */
    private static function latest_economic_by_code(array $values): array {
        $map = [];
        foreach ($values as $value) {
            $code = trim((string)($value->indicatorcode ?? ''));
            if ($code === '') {
                continue;
            }
            if (!isset($map[$code]) || (int)$value->periodend > (int)$map[$code]->periodend ||
                    ((int)$value->periodend === (int)$map[$code]->periodend &&
                        (int)$value->id > (int)$map[$code]->id)) {
                $map[$code] = $value;
            }
        }
        return $map;
    }

    /** Whether a timestamp falls between the two audit dates, inclusive. */
    private static function time_in_interval(int $time, int $fromdate, int $todate): bool {
        if ($time <= 0) {
            return false;
        }
        $start = min($fromdate, $todate);
        $end = max($fromdate, $todate) + DAYSECS - 1;
        return $time >= $start && $time <= $end;
    }
}
