<?php
// This file is part of Moodle - http://moodle.org/

namespace local_kbacorporate\local;

defined('MOODLE_INTERNAL') || die();

/** Read-only data models for role-oriented management dashboards. */
class management_dashboards {
    /** Build owner, CEO and HR panels for one company. */
    public static function build_company(int $companyid): \stdClass {
        global $DB;

        $now = time();
        $audits = $DB->get_records('local_kbacorp_impl_audit', [
            'companyid' => $companyid,
            'status' => implementation_audits::STATUS_CONFIRMED,
        ], 'auditdate DESC, id DESC');
        $latestaudit = $audits ? reset($audits) : null;
        $previousaudit = null;
        if ($latestaudit) {
            foreach ($audits as $audit) {
                if ((int)$audit->id !== (int)$latestaudit->id &&
                        (int)$audit->toolid === (int)$latestaudit->toolid) {
                    $previousaudit = $audit;
                    break;
                }
            }
        }

        $tasks = array_values($DB->get_records('local_kbacorp_impl_task',
            ['companyid' => $companyid, 'deleted' => 0], 'duedate ASC, id ASC'));
        $tasksummary = self::summarize_task_records($tasks, $now);
        $owners = self::load_users(array_column($tasks, 'ownerid'));
        foreach ($tasks as $task) {
            $task->ownername = !empty($owners[(int)$task->ownerid]) ?
                fullname($owners[(int)$task->ownerid]) : get_string('notassigned', 'local_kbacorporate');
        }

        $criticalsections = [];
        $sectionprogress = null;
        if ($latestaudit) {
            $sectionprogress = implementation_section_progress::build($companyid, (int)$latestaudit->id);
            $criticalsections = array_values($sectionprogress->sections);
            usort($criticalsections, static function($left, $right) {
                $risk = ((int)$right->attentioncriteria <=> (int)$left->attentioncriteria);
                $leftscore = $left->currentaverage === null ? PHP_FLOAT_MAX : (float)$left->currentaverage;
                $rightscore = $right->currentaverage === null ? PHP_FLOAT_MAX : (float)$right->currentaverage;
                return $risk ?: ($leftscore <=> $rightscore);
            });
            $criticalsections = array_slice($criticalsections, 0, 5);
        }

        $indicators = $DB->get_records_select('local_kbacorp_econ_ind',
            '(companyid = :global OR companyid = :companyid) AND active = 1',
            ['global' => 0, 'companyid' => $companyid], 'sortorder ASC, name ASC');
        $economic = self::latest_economic_values($companyid, $indicators);

        $members = (int)$DB->count_records('local_kbacorp_member', ['companyid' => $companyid, 'isemployee' => 1]);
        $roles = (int)$DB->count_records('local_kbacorp_busrole', ['companyid' => $companyid, 'active' => 1]);
        $roleassignments = (int)$DB->count_records('local_kbacorp_member_busrole',
            ['companyid' => $companyid, 'active' => 1]);

        $competencyaudits = $DB->get_records('local_kbacorp_audit', ['companyid' => $companyid]);
        $auditids = array_map('intval', array_keys($competencyaudits));
        $gaprecords = [];
        if ($auditids) {
            [$insql, $params] = $DB->get_in_or_equal($auditids, SQL_PARAMS_NAMED, 'ca');
            $params['confirmed'] = competency_audits::ITEM_CONFIRMED;
            $gaprecords = $DB->get_records_sql(
                "SELECT ai.* FROM {local_kbacorp_audit_item} ai
                  WHERE ai.auditid {$insql} AND ai.status = :confirmed",
                $params
            );
        }
        $gaps = self::summarize_gap_records(array_values($gaprecords));

        $plans = $DB->get_records('local_kbacorp_devplan', ['companyid' => $companyid]);
        $planids = array_map('intval', array_keys($plans));
        $planitems = [];
        if ($planids) {
            [$insql, $params] = $DB->get_in_or_equal($planids, SQL_PARAMS_NAMED, 'dp');
            $planitems = $DB->get_records_select('local_kbacorp_devitem', "planid {$insql}", $params);
        }
        $courseids = [];
        foreach ($planitems as $item) {
            if ((int)$item->courseid > 0) {
                $courseids[(int)$item->courseid] = true;
            }
        }
        $completioncount = 0;
        if ($courseids && $competencyaudits) {
            $userids = array_values(array_unique(array_map('intval', array_column(array_values($competencyaudits), 'userid'))));
            if ($userids) {
                [$coursesql, $courseparams] = $DB->get_in_or_equal(array_keys($courseids), SQL_PARAMS_NAMED, 'mc');
                [$usersql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'mu');
                $completioncount = (int)$DB->count_records_sql(
                    "SELECT COUNT(1) FROM {course_completions}
                      WHERE course {$coursesql} AND userid {$usersql} AND timecompleted IS NOT NULL",
                    $courseparams + $userparams
                );
            }
        }

        $trend = [];
        foreach (array_slice(array_reverse(array_values($audits)), -8) as $audit) {
            $trend[] = (object)[
                'auditid' => (int)$audit->id,
                'date' => (int)$audit->auditdate,
                'value' => $audit->indexvalue === null ? null : (float)$audit->indexvalue,
                'percent' => $audit->indexvalue === null ? null :
                    round(((float)$audit->indexvalue * 100) / max(1, (int)$audit->scalemax), 1),
            ];
        }

        return (object)[
            'latest' => $latestaudit,
            'previous' => $previousaudit,
            'indexdelta' => self::index_delta($latestaudit, $previousaudit),
            'trend' => $trend,
            'tasks' => $tasks,
            'tasksummary' => $tasksummary,
            'sectionprogress' => $sectionprogress,
            'criticalsections' => $criticalsections,
            'economic' => $economic,
            'members' => $members,
            'roles' => $roles,
            'roleassignments' => $roleassignments,
            'gaps' => $gaps,
            'plans' => self::count_statuses(array_values($plans)),
            'planitems' => self::count_statuses(array_values($planitems)),
            'coursecompletions' => $completioncount,
            'reassessments' => self::count_reassessments(array_values($competencyaudits)),
        ];
    }

    /** Build a curator portfolio without widening company access. */
    public static function build_portfolio(int $userid, bool $canmanage): array {
        global $DB;

        $companies = implementation_section_progress::get_accessible_companies($userid, $canmanage);
        $rows = [];
        foreach ($companies as $company) {
            $companyid = (int)$company->id;
            $audits = $DB->get_records('local_kbacorp_impl_audit', [
                'companyid' => $companyid,
                'status' => implementation_audits::STATUS_CONFIRMED,
            ], 'auditdate DESC, id DESC', 'id,toolid,indexvalue,auditdate,confirmedat', 0, 10);
            $latest = $audits ? reset($audits) : null;
            $previous = null;
            if ($latest) {
                foreach ($audits as $audit) {
                    if ((int)$audit->id !== (int)$latest->id && (int)$audit->toolid === (int)$latest->toolid) {
                        $previous = $audit;
                        break;
                    }
                }
            }
            $tasks = array_values($DB->get_records('local_kbacorp_impl_task',
                ['companyid' => $companyid, 'deleted' => 0], '', 'id,status,duedate,timemodified'));
            $summary = self::summarize_task_records($tasks, time());
            $evidencepending = (int)$DB->count_records_sql(
                "SELECT COUNT(1)
                   FROM {local_kbacorp_impl_evid} e
                   JOIN {local_kbacorp_impl_task} t ON t.id = e.taskid
                  WHERE t.companyid = :companyid AND t.deleted = 0 AND e.status = :status",
                ['companyid' => $companyid, 'status' => implementation_evidence::STATUS_SUBMITTED]
            );
            $program = $DB->get_record_sql(
                "SELECT * FROM {local_kbacorp_impl_program}
                  WHERE companyid = :companyid AND status IN ('active', 'paused')
               ORDER BY startdate DESC, id DESC",
                ['companyid' => $companyid], IGNORE_MULTIPLE
            );
            $lastactivity = $latest ? max((int)$latest->confirmedat, (int)$latest->auditdate) : 0;
            foreach ($tasks as $task) {
                $lastactivity = max($lastactivity, (int)$task->timemodified);
            }
            $rows[] = (object)[
                'company' => $company,
                'latest' => $latest,
                'indexdelta' => self::index_delta($latest, $previous),
                'tasksummary' => $summary,
                'evidencepending' => $evidencepending,
                'lastactivity' => $lastactivity,
                'noprogress' => $lastactivity === 0 || $lastactivity < time() - (30 * DAYSECS),
                'checkpoint' => self::next_checkpoint($tasks, $program),
            ];
        }
        usort($rows, static function($left, $right) {
            $risk = ((int)$right->noprogress <=> (int)$left->noprogress);
            return $risk ?: ((int)$right->tasksummary->overdue <=> (int)$left->tasksummary->overdue);
        });
        return $rows;
    }

    /** Aggregate task state without changing workflow records. */
    public static function summarize_task_records(array $tasks, int $now): \stdClass {
        $result = (object)['total' => 0, 'completed' => 0, 'active' => 0, 'overdue' => 0, 'progress' => 0];
        $progress = ['planned' => 0, 'inprogress' => 40, 'review' => 70, 'completed' => 100];
        $sum = 0;
        foreach ($tasks as $task) {
            $status = (string)$task->status;
            if ($status === 'cancelled') {
                continue;
            }
            $result->total++;
            $sum += $progress[$status] ?? 0;
            if ($status === 'completed') {
                $result->completed++;
            } else {
                $result->active++;
                if ((int)$task->duedate > 0 && (int)$task->duedate < $now) {
                    $result->overdue++;
                }
            }
        }
        $result->progress = $result->total ? (int)round($sum / $result->total) : 0;
        return $result;
    }

    /** Count only confirmed assessed competency rows; null targets stay unknown. */
    public static function summarize_gap_records(array $items): \stdClass {
        $result = (object)['assessed' => 0, 'gaps' => 0, 'critical' => 0, 'unknown' => 0];
        foreach ($items as $item) {
            if (!isset($item->targetlevel) || $item->targetlevel === null) {
                $result->unknown++;
                continue;
            }
            $result->assessed++;
            $gap = (int)$item->targetlevel - (int)$item->proposedlevel;
            if ($gap > 0) {
                $result->gaps++;
                if ($gap >= 2) {
                    $result->critical++;
                }
            }
        }
        return $result;
    }

    /** Safe audit index delta; missing values remain unknown. */
    public static function index_delta($latest, $previous): ?float {
        if (!$latest || !$previous || $latest->indexvalue === null || $previous->indexvalue === null) {
            return null;
        }
        return round((float)$latest->indexvalue - (float)$previous->indexvalue, 2);
    }

    private static function count_statuses(array $records): \stdClass {
        $result = (object)['total' => count($records), 'draft' => 0, 'pending' => 0,
            'active' => 0, 'completed' => 0, 'overdue' => 0];
        $now = time();
        foreach ($records as $record) {
            $status = (string)($record->status ?? '');
            if (property_exists($result, $status)) {
                $result->{$status}++;
            }
            $due = (int)($record->duedate ?? $record->targetdate ?? 0);
            if ($due > 0 && $due < $now && !in_array($status, ['completed', 'cancelled'], true)) {
                $result->overdue++;
            }
        }
        return $result;
    }

    private static function count_reassessments(array $audits): \stdClass {
        $result = (object)['total' => 0, 'completed' => 0, 'open' => 0];
        foreach ($audits as $audit) {
            if ((string)$audit->audittype !== competency_audits::AUDIT_TYPE_DEVELOPMENT) {
                continue;
            }
            $result->total++;
            if ((string)$audit->status === competency_audits::AUDIT_COMPLETED) {
                $result->completed++;
            } else {
                $result->open++;
            }
        }
        return $result;
    }

    private static function load_users(array $ids): array {
        global $DB;
        $ids = array_values(array_filter(array_unique(array_map('intval', $ids))));
        if (!$ids) {
            return [];
        }
        return $DB->get_records_list('user', 'id', $ids, '', 'id,firstname,lastname,firstnamephonetic,lastnamephonetic,middlename,alternatename');
    }

    private static function latest_economic_values(int $companyid, array $indicators): array {
        global $DB;
        if (!$indicators) {
            return [];
        }
        $values = $DB->get_records('local_kbacorp_econ_val', [
            'companyid' => $companyid,
            'scenario' => economic_indicators::SCENARIO_ACTUAL,
        ], 'periodend DESC, id DESC');
        $latest = [];
        foreach ($values as $value) {
            if (!isset($latest[(int)$value->indicatorid])) {
                $latest[(int)$value->indicatorid] = $value;
            }
        }
        $rows = [];
        foreach ($indicators as $indicator) {
            if (!empty($latest[(int)$indicator->id])) {
                $rows[] = (object)['indicator' => $indicator, 'value' => $latest[(int)$indicator->id]];
            }
            if (count($rows) >= 6) {
                break;
            }
        }
        return $rows;
    }

    private static function next_checkpoint(array $tasks, $program): int {
        $dates = [];
        foreach ($tasks as $task) {
            if (!in_array((string)$task->status, ['completed', 'cancelled'], true) && (int)$task->duedate > 0) {
                $dates[] = (int)$task->duedate;
            }
        }
        if ($program && (int)$program->enddate > 0) {
            $dates[] = (int)$program->enddate;
        }
        return $dates ? min($dates) : 0;
    }
}
