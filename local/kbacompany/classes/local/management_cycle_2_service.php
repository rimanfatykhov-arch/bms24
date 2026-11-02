<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * BMS24 Management Cycle 2.0 read model.
 *
 * The five management functions are a projection over the existing canonical
 * cycle, Battle Plan, meeting, metric, task and A3 objects. This service stores
 * no stage state and never creates a parallel management lifecycle.
 */
class management_cycle_2_service {
    public const CONTRACT_VERSION = '1.1.0-rc18';
    public const REGULAR_TEMPLATES = ['week', 'month', 'quarter', 'year'];
    public const STAGES = ['planning', 'organization', 'leadership', 'control', 'correction'];

    public static function contract(): array {
        return [
            'component' => 'local_kbacompany',
            'contract' => 'bms_management_cycle_2',
            'version' => self::CONTRACT_VERSION,
            'storage' => 'none',
            'sources' => [
                'cycle' => 'local_kbacompany_mcycle + local_kbacompany_mcyclecp',
                'execution' => 'canonical Battle Plans and Tasks',
                'meetings' => 'canonical BMS Meetings and Decisions',
                'metrics' => 'canonical BMS24 Metrics',
                'correction' => 'canonical A3 reviews and rollover references',
            ],
            'rules' => [
                'planning_organization_leadership_control_correction_are_derived_not_persisted',
                'no_parallel_task_metric_meeting_or_cycle_engine',
                'week_month_quarter_year_share_one_management_model',
                'stage_statuses_are_explainable_and_use_no_hidden_percentage_thresholds',
                'close_readiness_is_advisory_and_does_not_silently_rewrite_domain_objects',
                'all_reads_are_company_scoped',
            ],
        ];
    }

    private static function require_manage(string $companyuuid, int $actor): void {
        company_context_service::context($companyuuid, $actor);
        if (!access_role_service::can($companyuuid, $actor, 'management_cycle', 'view')) {
            throw new \required_capability_exception(\context_system::instance(),
                permission_service::CAPABILITY_MANAGE, 'nopermissions', '');
        }
    }

    private static function stage(string $code, string $state, array $facts, string $url): array {
        if (!in_array($code, self::STAGES, true)) { throw new \coding_exception('Unknown management stage.'); }
        if (!in_array($state, ['pending', 'ready', 'in_progress', 'done', 'attention'], true)) {
            throw new \coding_exception('Unknown management stage state.');
        }
        return ['code'=>$code, 'state'=>$state, 'facts'=>$facts, 'url'=>$url];
    }

    /** Current Year → Quarter → Month → Week cadence around the supplied date. */
    public static function cadence_overview(string $companyuuid, int $actor, string $date = ''): array {
        global $DB;
        self::require_manage($companyuuid, $actor);
        if ($date === '') { $date = business_date_service::day(time()); }
        if (!management_cycle_template_service::valid_date($date)) {
            throw new \moodle_exception('mcu_bad_date', 'local_kbacompany');
        }
        list($insql, $inparams) = $DB->get_in_or_equal(self::REGULAR_TEMPLATES, SQL_PARAMS_NAMED, 'm2type');
        $params = ['company'=>$companyuuid, 'date1'=>$date, 'date2'=>$date] + $inparams;
        $sql = "SELECT c.id,c.uuid,c.templatecode,c.title,c.status,c.periodstart,c.periodend,c.ownerid
                  FROM {local_kbacompany_mcycle} c
                 WHERE c.companyuuid = :company
                   AND c.templatecode {$insql}
                   AND c.status <> 'cancelled'
                   AND c.periodstart <= :date1
                   AND c.periodend >= :date2
              ORDER BY c.id DESC";
        $rank = ['active'=>0, 'draft'=>1, 'closed'=>2];
        $chosen = [];
        foreach ($DB->get_records_sql($sql, $params) as $row) {
            $code = (string)$row->templatecode;
            $currentrank = $rank[(string)$row->status] ?? 9;
            if (!isset($chosen[$code]) || $currentrank < $chosen[$code]['rank']) {
                $chosen[$code] = ['rank'=>$currentrank, 'row'=>$row];
            }
        }
        $out = [];
        foreach (['year','quarter','month','week'] as $code) {
            if (isset($chosen[$code])) {
                $row = $chosen[$code]['row'];
                $out[] = [
                    'templatecode'=>$code, 'exists'=>true, 'uuid'=>(string)$row->uuid,
                    'title'=>(string)$row->title, 'status'=>(string)$row->status,
                    'periodstart'=>(string)$row->periodstart, 'periodend'=>(string)$row->periodend,
                    'url'=>(new \moodle_url('/local/kbacompany/management_cycles.php', [
                        'uuid'=>$companyuuid, 'cycle'=>(string)$row->uuid,
                    ]))->out(false),
                ];
            } else {
                $out[] = [
                    'templatecode'=>$code, 'exists'=>false, 'uuid'=>'', 'title'=>'', 'status'=>'',
                    'periodstart'=>'', 'periodend'=>'',
                    'url'=>(new \moodle_url('/local/kbacompany/management_cycles.php', [
                        'uuid'=>$companyuuid, 'view'=>'create', 'templatecode'=>$code,
                    ]))->out(false),
                ];
            }
        }
        return ['date'=>$date, 'rows'=>$out, 'contract'=>self::contract()];
    }

    /** Five-function management projection for one regular canonical cycle. */
    public static function context(string $companyuuid, int $actor, string $cycleuuid): array {
        self::require_manage($companyuuid, $actor);
        $report = management_cycle_report_service::context($companyuuid, $actor, $cycleuuid);
        $cycle = $report['cycle'];
        $template = (string)$cycle['templatecode'];
        if (!in_array($template, self::REGULAR_TEMPLATES, true)) {
            return ['enabled'=>false, 'stages'=>[], 'contract'=>self::contract()];
        }

        $templates = management_cycle_template_service::templates();
        $definition = $templates[$template];
        $status = (string)$cycle['status'];
        $indicatorcount = count((array)$cycle['indicatoruuids']);
        $minindicators = (int)$definition['indicator_min'];
        $planningready = trim((string)$cycle['title']) !== '' && trim((string)$cycle['priority']) !== '' &&
            trim((string)$cycle['outcome']) !== '' && (int)$cycle['ownerid'] > 0 && (int)$cycle['curatorid'] > 0 &&
            $indicatorcount >= $minindicators;
        $planningstate = $status === 'draft' ? ($planningready ? 'ready' : 'attention') : 'done';

        $execution = (array)$report['execution']['summary'];
        $meetings = (array)$report['meetings']['summary'];
        $a3 = (array)$report['a3']['summary'];
        $plansrequired = !empty($definition['battleplan']);
        $organizationok = !$plansrequired || (int)($execution['plans'] ?? 0) > 0;
        if ($status === 'draft') { $organizationstate = 'pending'; }
        else if ($organizationok) { $organizationstate = 'done'; }
        else { $organizationstate = 'attention'; }

        $activity = (int)($execution['tasks'] ?? 0) + (int)($meetings['meetings'] ?? 0);
        if ($status === 'draft') { $leadershipstate = 'pending'; }
        else if ($status === 'closed') { $leadershipstate = 'done'; }
        else if ($activity > 0) { $leadershipstate = 'in_progress'; }
        else { $leadershipstate = 'attention'; }

        $cptotal = (int)$cycle['checkpoint_total'];
        $cpdone = (int)$cycle['checkpoint_done'];
        $cpoverdue = (int)$cycle['checkpoint_overdue'];
        $factmetrics = 0;
        $planmetrics = 0;
        foreach ((array)$report['indicators'] as $metric) {
            if ((int)($metric['fact_count'] ?? 0) > 0) { $factmetrics++; }
            if (!empty($metric['latest_plan'])) { $planmetrics++; }
        }
        if ($status === 'draft') { $controlstate = 'pending'; }
        else if ($cpoverdue > 0) { $controlstate = 'attention'; }
        else if ($cptotal > 0 && $cpdone >= $cptotal) { $controlstate = 'done'; }
        else { $controlstate = 'in_progress'; }

        $openA3 = max(0, (int)($a3['total'] ?? 0) - (int)($a3['closed'] ?? 0));
        $openTasks = (int)($execution['tasks_open'] ?? 0);
        $nextcycles = count((array)$report['next_cycles']);
        if ($status === 'draft') { $correctionstate = 'pending'; }
        else if ($status === 'closed') {
            $correctionstate = ($openA3 > 0 || ($openTasks > 0 && $nextcycles === 0)) ? 'attention' : 'done';
        } else if ($openA3 > 0 || (int)($a3['total'] ?? 0) > 0) {
            $correctionstate = 'in_progress';
        } else {
            $correctionstate = 'ready';
        }

        $base = '/local/kbacompany/management_cycles.php';
        $stages = [
            self::stage('planning', $planningstate, [
                'indicators'=>$indicatorcount, 'minimum_indicators'=>$minindicators, 'metrics_with_plan'=>$planmetrics,
                'owner_set'=>(int)$cycle['ownerid'] > 0, 'curator_set'=>(int)$cycle['curatorid'] > 0,
            ], (new \moodle_url($base, ['uuid'=>$companyuuid,'cycle'=>$cycleuuid,'tab'=>'overview']))->out(false)),
            self::stage('organization', $organizationstate, [
                'battle_plans'=>(int)($execution['plans'] ?? 0), 'battle_plans_required'=>$plansrequired,
                'checkpoints'=>$cptotal,
            ], (new \moodle_url($base, ['uuid'=>$companyuuid,'cycle'=>$cycleuuid,'tab'=>'execution']))->out(false)),
            self::stage('leadership', $leadershipstate, [
                'tasks'=>(int)($execution['tasks'] ?? 0), 'tasks_open'=>$openTasks,
                'meetings'=>(int)($meetings['meetings'] ?? 0), 'accepted_decisions'=>(int)($meetings['accepted_decisions'] ?? 0),
            ], (new \moodle_url($base, ['uuid'=>$companyuuid,'cycle'=>$cycleuuid,'tab'=>'execution']))->out(false)),
            self::stage('control', $controlstate, [
                'checkpoints_done'=>$cpdone, 'checkpoints_total'=>$cptotal, 'checkpoints_overdue'=>$cpoverdue,
                'metrics_with_fact'=>$factmetrics, 'metrics_total'=>count((array)$report['indicators']),
            ], (new \moodle_url($base, ['uuid'=>$companyuuid,'cycle'=>$cycleuuid,'tab'=>'checkpoints']))->out(false)),
            self::stage('correction', $correctionstate, [
                'a3_total'=>(int)($a3['total'] ?? 0), 'a3_open'=>$openA3,
                'open_tasks'=>$openTasks, 'next_cycles'=>$nextcycles,
            ], (new \moodle_url($base, ['uuid'=>$companyuuid,'cycle'=>$cycleuuid,'tab'=>'a3']))->out(false)),
        ];

        $summary = ['done'=>0, 'attention'=>0, 'active'=>0, 'pending'=>0];
        foreach ($stages as $stage) {
            if ($stage['state'] === 'done') { $summary['done']++; }
            else if ($stage['state'] === 'attention') { $summary['attention']++; }
            else if ($stage['state'] === 'pending') { $summary['pending']++; }
            else { $summary['active']++; }
        }
        $summary['close_attention'] = $cpoverdue + $openA3 + (($openTasks > 0 && $nextcycles === 0) ? $openTasks : 0);

        return [
            'enabled'=>true, 'templatecode'=>$template, 'status'=>$status, 'stages'=>$stages,
            'summary'=>$summary, 'contract'=>self::contract(),
        ];
    }
}
