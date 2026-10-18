<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Administrative Cycle projection over strategy/governance/planning, meetings, decisions and canonical tasks.
 *
 * The cycle does not create a second Decision or Task model. Goal/Policy/Plan/Program/Project use the
 * versioned Administrative Scale storage for backward compatibility; execution is projected from the
 * canonical Meeting/Decision/Task/Battle Plan services.
 */
class administrative_cycle_service {
    public const CONTRACT_VERSION = '0.4.8';
    public const PLANNING_KINDS = ['goal', 'policy', 'plan', 'program', 'project'];

    public static function contract(): array {
        $contract = [
            'component' => 'local_kbacompany',
            'contract' => 'bms_administrative_cycle',
            'version' => self::CONTRACT_VERSION,
            'company_scope' => 'required',
            'planning_objects' => ['StrategicGoalProjection', 'PolicyProjection', 'Plan', 'Program', 'Project'],
            'execution_objects' => ['Meeting', 'Decision', 'Task', 'WeeklyBattlePlan', 'Result'],
            'flow' => ['strategy_bsc', 'goal', 'policy', 'plan', 'program', 'project', 'meeting', 'decision',
                'task', 'battle_plan', 'result'],
            'rules' => [
                'legacy_administrative_cascade_remains_hidden_and_unchanged',
                'planning_children_pin_exact_published_parent_revisions',
                'meeting_pins_exact_published_project_revision',
                'decision_is_canonical_meeting_decision_not_cycle_copy',
                'task_is_canonical_task_not_cycle_copy',
                'battle_plan_references_task_without_copying',
                'result_comes_from_task_execution_record',
                'private_meeting_visibility_remains_participant_scoped',
                'historic_links_never_repoint_silently',
            ],
        ];
        $contract['schema_hash_algorithm'] = 'sha256';
        $contract['schema_hash'] = hash('sha256', json_encode($contract,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $contract;
    }

    private static function fail(string $key): void {
        throw new \moodle_exception('admincycle_' . $key, 'local_kbacompany');
    }

    public static function can_manage(string $companyuuid, int $userid): bool {
        return administrative_scale_service::can_manage($companyuuid, $userid);
    }

    /** Exact project source used to create a project-scoped meeting. */
    public static function project_source(string $companyuuid, int $actor, string $projectuuid, int $revision): array {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        if (!self::can_manage($companyuuid, $actor)) { self::fail('permission'); }
        $row = $DB->get_record('local_kbacompany_adminitem', [
            'companyuuid' => $companyuuid, 'uuid' => $projectuuid, 'revision' => $revision,
            'kind' => 'project', 'status' => 'published',
        ]);
        if (!$row) { self::fail('project'); }
        $path = self::admin_path($companyuuid, $row);
        return [
            'uuid' => (string)$row->uuid,
            'revision' => (int)$row->revision,
            'code' => (string)$row->code,
            'name' => (string)$row->name,
            'description' => (string)$row->description,
            'expected_result' => (string)$row->expectedresult,
            'path' => $path,
        ];
    }

    private static function admin_path(string $companyuuid, \stdClass $row): array {
        global $DB;
        $path = [];
        $cursor = $row;
        $seen = [];
        while ($cursor) {
            if (isset($seen[$cursor->uuid])) { self::fail('cycle'); }
            $seen[$cursor->uuid] = true;
            array_unshift($path, [
                'uuid' => (string)$cursor->uuid,
                'revision' => (int)$cursor->revision,
                'kind' => (string)$cursor->kind,
                'code' => (string)$cursor->code,
                'name' => (string)$cursor->name,
            ]);
            if ((string)$cursor->parentuuid === '') { break; }
            $cursor = $DB->get_record('local_kbacompany_adminitem', [
                'companyuuid' => $companyuuid,
                'uuid' => (string)$cursor->parentuuid,
                'revision' => (int)$cursor->parentrevision,
            ]);
            if (!$cursor) { self::fail('reference'); }
        }
        return $path;
    }

    /** Count Battle Plan references visible under the same access policy as Battle Plan itself. */
    private static function battle_projection(string $companyuuid, int $actor, array $task): array {
        global $DB;
        $canmanage = task_service::can_manage($companyuuid, $actor);
        if (!$canmanage && (int)$task['assignee_id'] !== $actor) {
            return ['count' => 0, 'visible' => false, 'url' => ''];
        }
        $params = ['company' => $companyuuid, 'provider' => 'bms', 'externalid' => $task['uuid'], 'taskuuid' => $task['uuid']];
        $sql = "SELECT bi.id, bp.userid, bp.uuid AS planuuid, bp.periodstart
                  FROM {local_kbacompany_bplanitem} bi
                  JOIN {local_kbacompany_bplan} bp ON bp.companyuuid = bi.companyuuid AND bp.uuid = bi.planuuid
                 WHERE bi.companyuuid = :company AND bi.provider = :provider
                   AND (bi.externalid = :externalid OR (bi.externalid = '' AND bi.taskuuid = :taskuuid))";
        if (!$canmanage) {
            $sql .= " AND bp.userid = :userid";
            $params['userid'] = $actor;
        }
        $rows = $DB->get_records_sql($sql, $params);
        $url = '';
        if ($rows) {
            $first = reset($rows);
            $url = (new \moodle_url('/local/kbacompany/battle_plan.php', [
                'uuid' => $companyuuid, 'plan' => (string)$first->planuuid,
                'user' => (int)$first->userid, 'week' => (string)$first->periodstart,
            ]))->out(false);
        } else if ($canmanage || (int)$task['assignee_id'] === $actor) {
            $url = (new \moodle_url('/local/kbacompany/battle_plan.php', [
                'uuid' => $companyuuid, 'user' => (int)$task['assignee_id'],
            ]))->out(false);
        }
        return ['count' => count($rows), 'visible' => true, 'url' => $url];
    }

    private static function execution_for_project(string $companyuuid, int $actor, array $project,
            array $visiblemeetings): array {
        $meetings = [];
        $accepted = 0;
        $tasks = 0;
        $done = 0;
        $results = 0;
        foreach ($visiblemeetings as $meeting) {
            if (($meeting['source_kind'] ?? '') !== 'admin_project' ||
                    ($meeting['source_uuid'] ?? '') !== $project['uuid']) {
                continue;
            }
            $meeting['source_revision_matches_current'] = (int)$meeting['source_revision'] === (int)$project['revision'];
            foreach ($meeting['decisions'] as &$decision) {
                if ($decision['status'] === 'accepted') { $accepted++; }
                foreach ($decision['tasks'] as &$task) {
                    $tasks++;
                    if ($task['status'] === 'done') { $done++; }
                    if (trim((string)$task['result']) !== '') { $results++; }
                    $task['battle_plan'] = self::battle_projection($companyuuid, $actor, $task);
                    $task['url'] = (new \moodle_url('/local/kbacompany/tasks.php', [
                        'uuid' => $companyuuid, 'task' => $task['uuid'],
                    ]))->out(false);
                }
                unset($task);
            }
            unset($decision);
            $meeting['url'] = (new \moodle_url('/local/kbacompany/meetings.php', [
                'uuid' => $companyuuid, 'meeting' => $meeting['uuid'],
            ]))->out(false);
            $meetings[] = $meeting;
        }
        return [
            'meetings' => $meetings,
            'accepted_decisions' => $accepted,
            'tasks' => $tasks,
            'done_tasks' => $done,
            'results' => $results,
        ];
    }

    private static function recommendation(array $bykind, array $summary): string {
        foreach (self::PLANNING_KINDS as $kind) {
            if (empty($bykind[$kind])) { return 'create_' . $kind; }
        }
        if ($summary['meetings'] === 0) { return 'create_meeting'; }
        if ($summary['accepted_decisions'] === 0) { return 'accept_decision'; }
        if ($summary['tasks'] === 0) { return 'create_task'; }
        if ($summary['results'] < $summary['tasks']) { return 'execute_tasks'; }
        return 'review_results';
    }

    public static function context(string $companyuuid, int $actor, string $selectedproject = ''): array {
        global $DB;
        require_capability('local/kbacompany:viewowncompany', \context_system::instance(), $actor);
        $admin = administrative_scale_service::context($companyuuid, $actor);
        $meetingcontext = meeting_service::context($companyuuid, $actor);
        $items = [];
        $bykind = array_fill_keys(self::PLANNING_KINDS, []);
        foreach ($admin['items'] as $item) {
            if (!in_array($item['kind'], self::PLANNING_KINDS, true)) { continue; }
            $items[] = $item;
            $bykind[$item['kind']][] = $item;
        }

        $summary = [
            'goals' => count($bykind['goal']),
            'policies' => count($bykind['policy']),
            'plans' => count($bykind['plan']),
            'programs' => count($bykind['program']),
            'projects' => count($bykind['project']),
            'meetings' => 0,
            'accepted_decisions' => 0,
            'tasks' => 0,
            'done_tasks' => 0,
            'results' => 0,
        ];
        $projects = [];
        foreach ($bykind['project'] as $project) {
            $execution = self::execution_for_project($companyuuid, $actor, $project, $meetingcontext['meetings']);
            $project['execution'] = $execution;
            $project['can_start_meeting'] = $admin['can_manage'] && $project['status'] === 'published' && !$project['stale'];
            $project['meeting_url'] = $project['can_start_meeting'] ? (new \moodle_url('/local/kbacompany/meetings.php', [
                'uuid' => $companyuuid, 'cycle_project' => $project['uuid'], 'cycle_revision' => $project['revision'],
            ]))->out(false) : '';
            $summary['meetings'] += count($execution['meetings']);
            $summary['accepted_decisions'] += $execution['accepted_decisions'];
            $summary['tasks'] += $execution['tasks'];
            $summary['done_tasks'] += $execution['done_tasks'];
            $summary['results'] += $execution['results'];
            $projects[] = $project;
        }
        $selected = null;
        if ($selectedproject !== '') {
            foreach ($projects as $project) {
                if ($project['uuid'] === $selectedproject) { $selected = $project; break; }
            }
            if ($selected === null) { self::fail('project'); }
        } else if ($projects) {
            $selected = reset($projects);
        }

        $legacydecisioncount = 0;
        $legacytaskcount = 0;
        foreach ($admin['items'] as $item) {
            if ($item['kind'] === 'decision') { $legacydecisioncount++; }
            if ($item['kind'] === 'task') { $legacytaskcount++; }
        }

        return [
            'contract' => self::contract(),
            'company' => $admin['company'],
            'can_manage' => $admin['can_manage'],
            'planning_kinds' => self::PLANNING_KINDS,
            'items' => $items,
            'by_kind' => $bykind,
            'projects' => $projects,
            'selected_project' => $selected,
            'parent_options' => $admin['parent_options'],
            'strategic_goals' => $admin['strategic_goals'],
            'policies' => $admin['policies'],
            'positions' => $admin['positions'],
            'products' => $admin['products'],
            'metrics' => $admin['metrics'],
            'summary' => $summary,
            'recommendation' => self::recommendation($bykind, $summary),
            'legacy_compatibility' => [
                'decision_definitions' => $legacydecisioncount,
                'task_definitions' => $legacytaskcount,
                'cascade_objects' => (int)$admin['legacy_cascade_count'],
            ],
        ];
    }

    /** Resolve an old URL using the same visible objects as the canonical editor. No writes. */
    public static function legacy_destination(string $companyuuid, int $actor, string $projectuuid = '',
            string $edituuid = ''): \moodle_url {
        $admin = administrative_scale_service::context($companyuuid, $actor);
        $params = ['uuid' => $companyuuid, 'viewkind' => 'project'];
        $wanted = $edituuid !== '' ? $edituuid : $projectuuid;
        if ($wanted !== '') {
            $found = null;
            foreach ($admin['items'] as $item) {
                if ($item['uuid'] === $wanted) { $found = $item; break; }
            }
            if (!$found || ($edituuid === '' && $found['kind'] !== 'project')) { self::fail('project'); }
            $params['viewkind'] = $found['kind'];
            if ($edituuid !== '') {
                if (empty($admin['can_manage'])) { self::fail('permission'); }
                $params['edituuid'] = $found['uuid'];
            } else {
                $params['detailuuid'] = $found['uuid'];
                $params['recordtab'] = 'execution';
            }
        }
        return new \moodle_url('/local/kbacompany/administrative_scale.php', $params);
    }

    /** Project-card read model; no persistence, task generation or completion side effects. */
    public static function project_execution(string $companyuuid, int $actor, string $projectuuid): array {
        $admin = administrative_scale_service::context($companyuuid, $actor);
        $project = null;
        foreach ($admin['items'] as $item) {
            if ($item['uuid'] === $projectuuid && $item['kind'] === 'project') { $project = $item; break; }
        }
        // Never choose a first project silently, or trust a request-supplied row/revision.
        if ($project === null) { self::fail('project'); }
        $meetings = meeting_service::for_project($companyuuid, $actor, $projectuuid);
        $tasks = task_service::for_project($companyuuid, $actor, $projectuuid);
        $summary = ['tasks' => count($tasks), 'done' => 0, 'results' => 0, 'meetings' => count($meetings), 'decisions' => 0];
        foreach ($tasks as &$task) {
            $task['url'] = (new \moodle_url('/local/kbacompany/tasks.php', [
                'uuid' => $companyuuid, 'task' => $task['uuid'],
            ]))->out(false);
            $task['battle_plans'] = self::project_battle_plans($companyuuid, $actor, $task['uuid']);
            $task['project_revision_differs'] = $task['context_kind'] === 'project' &&
                $task['context_uuid'] === $projectuuid && $task['context_revision'] !== (int)$project['revision'];
            if ($task['status'] === 'done') { $summary['done']++; }
            if (trim((string)$task['result']) !== '') { $summary['results']++; }
        }
        unset($task);
        foreach ($meetings as &$meeting) {
            $meeting['url'] = (new \moodle_url('/local/kbacompany/meetings.php', [
                'uuid' => $companyuuid, 'meeting' => $meeting['uuid'],
            ]))->out(false);
            $meeting['project_revision_differs'] = (int)$meeting['source_revision'] !== (int)$project['revision'];
            foreach ($meeting['decisions'] as $decision) {
                if ($decision['status'] === 'accepted') { $summary['decisions']++; }
            }
        }
        unset($meeting);
        $labels = ['owner' => '', 'product' => '', 'indicator' => ''];
        foreach (['positions' => ['owner', 'ownerpositionuuid'], 'products' => ['product', 'productuuid'],
                'metrics' => ['indicator', 'metricuuid']] as $collection => $binding) {
            foreach ($admin[$collection] as $row) {
                if ($row['uuid'] === $project[$binding[1]]) { $labels[$binding[0]] = $row['name']; break; }
            }
        }
        $canstart = $admin['can_manage'] && $project['status'] === 'published' && !$project['stale'];
        return [
            'project' => $project, 'labels' => $labels, 'tasks' => $tasks, 'meetings' => $meetings,
            'summary' => $summary,
            'tasks_url' => (new \moodle_url('/local/kbacompany/tasks.php', ['uuid' => $companyuuid]))->out(false),
            'meeting_url' => $canstart ? (new \moodle_url('/local/kbacompany/meetings.php', [
                'uuid' => $companyuuid, 'cycle_project' => $projectuuid, 'cycle_revision' => $project['revision'],
            ]))->out(false) : '',
            'indicator_url' => $labels['indicator'] !== '' ? (new \moodle_url('/local/kbacompany/indicators.php', [
                'uuid' => $companyuuid, 'stat' => $project['metricuuid'],
            ]))->out(false) : '',
        ];
    }

    /** Canonical plan references, with plan participant/management visibility in SQL. */
    private static function project_battle_plans(string $companyuuid, int $actor, string $taskuuid): array {
        global $DB;
        $params = ['company' => $companyuuid, 'provider' => 'bms', 'externalid' => $taskuuid, 'taskuuid' => $taskuuid];
        $sql = "SELECT DISTINCT bp.id, bp.uuid, bp.userid, bp.periodstart
                  FROM {local_kbacompany_bplanitem} bi
                  JOIN {local_kbacompany_bplan} bp ON bp.companyuuid = bi.companyuuid AND bp.uuid = bi.planuuid
                 WHERE bi.companyuuid = :company AND bi.provider = :provider
                   AND (bi.externalid = :externalid OR (bi.externalid = '' AND bi.taskuuid = :taskuuid))";
        if (!task_service::can_manage($companyuuid, $actor)) {
            $sql .= " AND (bp.userid = :planowner OR bp.reviewerid = :planreviewer OR bp.observerid = :planobserver)";
            $params += ['planowner' => $actor, 'planreviewer' => $actor, 'planobserver' => $actor];
        }
        $sql .= ' ORDER BY bp.periodstart DESC, bp.id DESC';
        $plans = [];
        foreach ($DB->get_records_sql($sql, $params) as $row) {
            $plans[] = ['uuid' => (string)$row->uuid, 'periodstart' => (string)$row->periodstart,
                'url' => (new \moodle_url('/local/kbacompany/battle_plan.php', [
                    'uuid' => $companyuuid, 'plan' => $row->uuid, 'user' => $row->userid, 'week' => $row->periodstart,
                ]))->out(false)];
        }
        return $plans;
    }


}
