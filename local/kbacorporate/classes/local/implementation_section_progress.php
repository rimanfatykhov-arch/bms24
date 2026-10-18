<?php
namespace local_kbacorporate\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Read-only progress projection for BMS implementation sections.
 *
 * The projection intentionally keeps audit maturity, implementation actions and
 * accepted evidence as separate measures. Completing an action never rewrites
 * the immutable score of a confirmed audit.
 */
final class implementation_section_progress {
    /** Managed task statuses included in execution progress. */
    private const STATUS_PROGRESS = [
        'planned' => 0,
        'inprogress' => 35,
        'review' => 70,
        'completed' => 100,
    ];

    /** Return active companies visible either through management access or curator assignment. */
    public static function get_accessible_companies(int $userid, bool $canmanage): array {
        global $DB;

        $companies = [];
        if ($canmanage) {
            foreach (access::get_allowed_companies($userid) as $company) {
                if (access::has_full_company_access((int)$company->id, $userid)) {
                    $companies[(int)$company->id] = $company;
                }
            }
        }

        $curated = $DB->get_records_sql(
            "SELECT DISTINCT c.*
               FROM {local_kbacorp_company} c
               JOIN {local_kbacorp_impl_program} p ON p.companyid = c.id
              WHERE p.curatorid = :curatorid
                AND c.active = 1
           ORDER BY c.name ASC",
            ['curatorid' => $userid]
        );
        foreach ($curated as $company) {
            $companies[(int)$company->id] = $company;
        }

        uasort($companies, static function($left, $right) {
            return strnatcasecmp((string)$left->name, (string)$right->name);
        });
        return $companies;
    }

    /** Whether a user may open section progress for the company. */
    public static function can_view_company(int $companyid, int $userid, bool $canmanage): bool {
        global $DB;

        if ($canmanage && access::has_full_company_access($companyid, $userid)) {
            return true;
        }
        return $DB->record_exists('local_kbacorp_impl_program', [
            'companyid' => $companyid,
            'curatorid' => $userid,
        ]);
    }

    /** Confirmed audits available for the progress dashboard. */
    public static function get_confirmed_audits(int $companyid): array {
        global $DB;

        return $DB->get_records_sql(
            "SELECT a.*, t.name AS toolname, t.code AS toolcode,
                    p.id AS programid, p.status AS programstatus,
                    p.commercialstatus AS programcommercialstatus
               FROM {local_kbacorp_impl_audit} a
               JOIN {local_kbacorp_bms_tool} t ON t.id = a.toolid
          LEFT JOIN {local_kbacorp_impl_program} p ON p.baselineauditid = a.id
              WHERE a.companyid = :companyid
                AND a.status = :status
           ORDER BY a.auditdate DESC, a.id DESC",
            ['companyid' => $companyid, 'status' => implementation_audits::STATUS_CONFIRMED]
        );
    }

    /** Prefer the baseline of an active paid programme, otherwise use the latest confirmed audit. */
    public static function default_audit(array $audits): ?\stdClass {
        foreach ($audits as $audit) {
            if ((string)($audit->programcommercialstatus ?? '') === 'paid' &&
                    in_array((string)($audit->programstatus ?? ''), ['active', 'paused'], true)) {
                return $audit;
            }
        }
        return $audits ? reset($audits) : null;
    }

    /** Build the complete company/audit progress model. */
    public static function build(int $companyid, int $auditid): \stdClass {
        $audit = implementation_audits::get_audit($auditid);
        if ((int)$audit->companyid !== $companyid) {
            throw new \moodle_exception('companynotallowed', 'local_kbacorporate');
        }
        if ((string)$audit->status !== implementation_audits::STATUS_CONFIRMED) {
            throw new \moodle_exception('sectionprogressauditnotconfirmed', 'local_kbacorporate');
        }

        $items = implementation_audits::get_items($auditid);
        $tasks = self::get_tasks($companyid, $auditid);
        $evidence = self::get_latest_evidence($tasks);
        $previous = self::get_previous_audit($audit);
        $previousaverages = [];
        if ($previous) {
            $previousaverages = self::calculate_section_averages(
                implementation_audits::get_items((int)$previous->id)
            );
        }

        $model = self::aggregate_records($audit, $items, $tasks, $evidence, $previousaverages);
        $model->audit = $audit;
        $model->previousaudit = $previous;
        $model->program = implementation_programs::get_for_audit($auditid);
        return $model;
    }

    /**
     * Aggregate audit items, implementation tasks and latest evidence by section.
     *
     * This method is deliberately record-based so the metric contract can be
     * regression-tested without writing production records.
     */
    public static function aggregate_records(\stdClass $audit, array $items, array $tasks,
            array $latestevidence = [], array $previousaverages = []): \stdClass {
        $sections = [];
        $itemsection = [];
        $gaprows = [];
        $today = usergetmidnight(time());

        foreach ($items as $item) {
            $key = self::section_key($item);
            if (!isset($sections[$key])) {
                $sections[$key] = self::empty_section($key, $item);
            }
            $section = $sections[$key];
            $itemsection[(int)$item->id] = $key;

            if ((string)$item->evidencestatus === implementation_audits::EVIDENCE_NOT_APPLICABLE) {
                $section->notapplicable++;
                continue;
            }

            $section->totalcriteria++;
            $assessed = self::item_is_assessed($item);
            $targetknown = (int)$item->targetscore >= 0;
            if ($targetknown) {
                $section->targetsum += (int)$item->targetscore;
                $section->targetcount++;
            }

            if (!$assessed) {
                $section->unassessedcriteria++;
                continue;
            }

            $section->assessedcriteria++;
            $section->scoresum += (int)$item->score;
            $section->scorecount++;
            if ((int)$item->score < (int)$audit->attentionthreshold) {
                $section->attentioncriteria++;
            }

            if (!$targetknown) {
                continue;
            }

            $gap = max(0, (int)$item->targetscore - (int)$item->score);
            if ($gap === 0) {
                $section->achievedcriteria++;
                continue;
            }

            $section->gapcriteria++;
            $section->gappoints += $gap;
            $row = (object)[
                'itemid' => (int)$item->id,
                'criterionid' => (int)$item->criterionid,
                'criterioncode' => (string)$item->criterioncode,
                'criterionname' => (string)$item->criterionname,
                'score' => (int)$item->score,
                'targetscore' => (int)$item->targetscore,
                'gap' => $gap,
                'priority' => implementation_audits::gap_priority($gap),
                'tasks' => [],
            ];
            $section->criteria[] = $row;
            $gaprows[(int)$item->id] = $row;
        }

        $covereditems = [];
        $completeditems = [];
        $accepteditems = [];
        foreach ($tasks as $task) {
            $itemid = (int)$task->itemid;
            if (!isset($itemsection[$itemid])) {
                continue;
            }
            $section = $sections[$itemsection[$itemid]];
            $status = (string)$task->status;
            if (isset($gaprows[$itemid])) {
                $gaprows[$itemid]->tasks[] = $task;
            }
            if ($status === 'cancelled') {
                $section->cancelledtasks++;
                continue;
            }

            $section->tasktotal++;
            if (property_exists($section, $status . 'tasks')) {
                $field = $status . 'tasks';
                $section->{$field}++;
            }
            if (isset(self::STATUS_PROGRESS[$status])) {
                $section->workflowprogresssum += self::STATUS_PROGRESS[$status];
                $section->workflowprogresscount++;
            }
            if ((int)$task->ownerid === 0 && !in_array($status, ['completed', 'cancelled'], true)) {
                $section->unassignedtasks++;
            }
            if ((int)$task->duedate > 0 && (int)$task->duedate < $today &&
                    !in_array($status, ['completed', 'cancelled'], true)) {
                $section->overduetasks++;
            }

            if (isset($gaprows[$itemid])) {
                $covereditems[$itemsection[$itemid] . ':' . $itemid] = true;
                if ($status === 'completed') {
                    $completeditems[$itemsection[$itemid] . ':' . $itemid] = true;
                }
            }

            $latest = $latestevidence[(int)$task->id] ?? null;
            $task->latestevidence = $latest ?: null;
            if ($latest) {
                $evidencestatus = (string)$latest->status;
                if ($evidencestatus === implementation_evidence::STATUS_ACCEPTED) {
                    $section->acceptedevidence++;
                    if (isset($gaprows[$itemid])) {
                        $accepteditems[$itemsection[$itemid] . ':' . $itemid] = true;
                    }
                } else if ($evidencestatus === implementation_evidence::STATUS_SUBMITTED) {
                    $section->submittedevidence++;
                } else if ($evidencestatus === implementation_evidence::STATUS_REJECTED) {
                    $section->rejectedevidence++;
                }
            }
        }

        $summary = (object)[
            'sectioncount' => count($sections),
            'sectionswithgaps' => 0,
            'sectionscomplete' => 0,
            'sectionsrisk' => 0,
            'gapcriteria' => 0,
            'coveredcriteria' => 0,
            'completedcriteria' => 0,
            'acceptedcriteria' => 0,
            'tasktotal' => 0,
            'completedtasks' => 0,
            'overduetasks' => 0,
            'unassignedtasks' => 0,
            'acceptedevidence' => 0,
            'submittedevidence' => 0,
            'workflowprogresssum' => 0,
            'workflowprogresscount' => 0,
            'actioncoverage' => 0.0,
            'workflowprogress' => 0.0,
        ];

        foreach ($sections as $key => $section) {
            $section->currentaverage = $section->scorecount
                ? round($section->scoresum / $section->scorecount, 2) : null;
            $section->targetaverage = $section->targetcount
                ? round($section->targetsum / $section->targetcount, 2) : null;
            $section->scoregap = ($section->currentaverage !== null && $section->targetaverage !== null)
                ? round(max(0, $section->targetaverage - $section->currentaverage), 2) : null;
            $section->previousaverage = array_key_exists($key, $previousaverages)
                ? $previousaverages[$key] : null;
            $section->scorechange = ($section->currentaverage !== null &&
                    $section->previousaverage !== null)
                ? round($section->currentaverage - $section->previousaverage, 2) : null;

            $section->coveredcriteria = self::count_keys_for_section($covereditems, $key);
            $section->completedcriteria = self::count_keys_for_section($completeditems, $key);
            $section->acceptedcriteria = self::count_keys_for_section($accepteditems, $key);
            $section->actioncoverage = $section->gapcriteria
                ? round(($section->coveredcriteria * 100) / $section->gapcriteria, 1) : 100.0;
            $section->completioncoverage = $section->gapcriteria
                ? round(($section->completedcriteria * 100) / $section->gapcriteria, 1) : 100.0;
            $section->workflowprogress = $section->workflowprogresscount
                ? round($section->workflowprogresssum / $section->workflowprogresscount, 1) : 0.0;
            $section->status = self::section_status($section);

            if ($section->gapcriteria > 0) {
                $summary->sectionswithgaps++;
            }
            if ($section->status === 'complete' || $section->status === 'achieved') {
                $summary->sectionscomplete++;
            }
            if ($section->status === 'risk') {
                $summary->sectionsrisk++;
            }
            $summary->gapcriteria += $section->gapcriteria;
            $summary->coveredcriteria += $section->coveredcriteria;
            $summary->completedcriteria += $section->completedcriteria;
            $summary->acceptedcriteria += $section->acceptedcriteria;
            $summary->tasktotal += $section->tasktotal;
            $summary->completedtasks += $section->completedtasks;
            $summary->overduetasks += $section->overduetasks;
            $summary->unassignedtasks += $section->unassignedtasks;
            $summary->acceptedevidence += $section->acceptedevidence;
            $summary->submittedevidence += $section->submittedevidence;
            $summary->workflowprogresssum += $section->workflowprogresssum;
            $summary->workflowprogresscount += $section->workflowprogresscount;
        }

        $summary->actioncoverage = $summary->gapcriteria
            ? round(($summary->coveredcriteria * 100) / $summary->gapcriteria, 1) : 100.0;
        $summary->workflowprogress = $summary->workflowprogresscount
            ? round($summary->workflowprogresssum / $summary->workflowprogresscount, 1) : 0.0;
        unset($summary->workflowprogresssum, $summary->workflowprogresscount);

        return (object)[
            'sections' => array_values($sections),
            'summary' => $summary,
        ];
    }

    /** Stable status contract used by the page and tests. */
    public static function section_status(\stdClass $section): string {
        if ((int)$section->assessedcriteria === 0 && (int)$section->totalcriteria > 0) {
            return 'unassessed';
        }
        if ((int)$section->gapcriteria === 0) {
            return (int)$section->unassessedcriteria > 0 ? 'unassessed' : 'achieved';
        }
        if ((int)$section->overduetasks > 0) {
            return 'risk';
        }
        if ((int)$section->coveredcriteria === 0) {
            return 'noactions';
        }
        if ((int)$section->coveredcriteria < (int)$section->gapcriteria) {
            return 'partial';
        }
        if ((int)$section->completedcriteria >= (int)$section->gapcriteria) {
            return 'complete';
        }
        if ((int)$section->reviewtasks > 0) {
            return 'review';
        }
        return 'inprogress';
    }

    /** Find the previous confirmed audit of the same company and tool. */
    private static function get_previous_audit(\stdClass $audit): ?\stdClass {
        global $DB;

        $record = $DB->get_record_sql(
            "SELECT a.*, t.name AS toolname, t.code AS toolcode
               FROM {local_kbacorp_impl_audit} a
               JOIN {local_kbacorp_bms_tool} t ON t.id = a.toolid
              WHERE a.companyid = :companyid
                AND a.toolid = :toolid
                AND a.status = :status
                AND (a.auditdate < :auditdate
                     OR (a.auditdate = :auditdate2 AND a.id < :auditid))
           ORDER BY a.auditdate DESC, a.id DESC",
            [
                'companyid' => (int)$audit->companyid,
                'toolid' => (int)$audit->toolid,
                'status' => implementation_audits::STATUS_CONFIRMED,
                'auditdate' => (int)$audit->auditdate,
                'auditdate2' => (int)$audit->auditdate,
                'auditid' => (int)$audit->id,
            ], IGNORE_MULTIPLE
        );
        return $record ?: null;
    }

    /** Fetch tasks with owner labels for one audit. */
    private static function get_tasks(int $companyid, int $auditid): array {
        global $DB;

        return array_values($DB->get_records_sql(
            "SELECT t.*, u.firstname, u.lastname, u.firstnamephonetic,
                    u.lastnamephonetic, u.middlename, u.alternatename
               FROM {local_kbacorp_impl_task} t
          LEFT JOIN {user} u ON u.id = t.ownerid
              WHERE t.companyid = :companyid
                AND t.auditid = :auditid
                AND t.deleted = 0
           ORDER BY t.itemid ASC, t.id ASC",
            ['companyid' => $companyid, 'auditid' => $auditid]
        ));
    }

    /** Fetch only the latest evidence version for each task. */
    private static function get_latest_evidence(array $tasks): array {
        global $DB;

        if (!$tasks || !$DB->get_manager()->table_exists(new \xmldb_table('local_kbacorp_impl_evid'))) {
            return [];
        }
        $taskids = array_map(static function($task) {
            return (int)$task->id;
        }, $tasks);
        list($insql, $params) = $DB->get_in_or_equal($taskids, SQL_PARAMS_NAMED, 'sectiontask');
        $records = $DB->get_records_sql(
            "SELECT e.*
               FROM {local_kbacorp_impl_evid} e
              WHERE e.taskid {$insql}
           ORDER BY e.taskid ASC, e.versionno DESC, e.id DESC",
            $params
        );
        $latest = [];
        foreach ($records as $record) {
            if (!isset($latest[(int)$record->taskid])) {
                $latest[(int)$record->taskid] = $record;
            }
        }
        return $latest;
    }

    /** Calculate previous section averages with the same assessed-item rule. */
    private static function calculate_section_averages(array $items): array {
        $totals = [];
        foreach ($items as $item) {
            if (!self::item_is_assessed($item) ||
                    (string)$item->evidencestatus === implementation_audits::EVIDENCE_NOT_APPLICABLE) {
                continue;
            }
            $key = self::section_key($item);
            if (!isset($totals[$key])) {
                $totals[$key] = ['sum' => 0, 'count' => 0];
            }
            $totals[$key]['sum'] += (int)$item->score;
            $totals[$key]['count']++;
        }
        $averages = [];
        foreach ($totals as $key => $total) {
            $averages[$key] = $total['count']
                ? round($total['sum'] / $total['count'], 2) : null;
        }
        return $averages;
    }

    /** Whether an audit item has an assessed score suitable for section statistics. */
    private static function item_is_assessed(\stdClass $item): bool {
        return (int)$item->score >= 0 && !in_array((string)$item->evidencestatus, [
            implementation_audits::EVIDENCE_MISSING,
            implementation_audits::EVIDENCE_NEEDS_DATA,
            implementation_audits::EVIDENCE_NOT_APPLICABLE,
        ], true);
    }

    /** Build a stable section key from the immutable audit snapshot. */
    private static function section_key(\stdClass $item): string {
        $code = trim((string)($item->sectioncode ?? ''));
        return $code !== '' ? $code : 'general';
    }

    /** Initial mutable accumulator for one section. */
    private static function empty_section(string $key, \stdClass $item): \stdClass {
        return (object)[
            'code' => $key,
            'name' => trim((string)($item->sectionname ?? '')),
            'totalcriteria' => 0,
            'assessedcriteria' => 0,
            'unassessedcriteria' => 0,
            'notapplicable' => 0,
            'attentioncriteria' => 0,
            'gapcriteria' => 0,
            'achievedcriteria' => 0,
            'gappoints' => 0,
            'scoresum' => 0,
            'scorecount' => 0,
            'targetsum' => 0,
            'targetcount' => 0,
            'currentaverage' => null,
            'targetaverage' => null,
            'scoregap' => null,
            'previousaverage' => null,
            'scorechange' => null,
            'criteria' => [],
            'tasktotal' => 0,
            'plannedtasks' => 0,
            'inprogresstasks' => 0,
            'reviewtasks' => 0,
            'completedtasks' => 0,
            'cancelledtasks' => 0,
            'overduetasks' => 0,
            'unassignedtasks' => 0,
            'acceptedevidence' => 0,
            'submittedevidence' => 0,
            'rejectedevidence' => 0,
            'workflowprogresssum' => 0,
            'workflowprogresscount' => 0,
            'coveredcriteria' => 0,
            'completedcriteria' => 0,
            'acceptedcriteria' => 0,
            'actioncoverage' => 0.0,
            'completioncoverage' => 0.0,
            'workflowprogress' => 0.0,
            'status' => 'unassessed',
        ];
    }

    /** Count item markers belonging to one section. */
    private static function count_keys_for_section(array $markers, string $sectionkey): int {
        $prefix = $sectionkey . ':';
        $count = 0;
        foreach ($markers as $key => $unused) {
            if (strpos((string)$key, $prefix) === 0) {
                $count++;
            }
        }
        return $count;
    }
}
