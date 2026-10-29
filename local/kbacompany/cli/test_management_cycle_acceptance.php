<?php
/** Pure source projection regression; no Moodle bootstrap or DB. */
define('MOODLE_INTERNAL', true);
// Absolute dashboard URLs are only valid on the configured site origin.
$CFG = (object)['wwwroot' => 'https://bms24.example'];
require dirname(__DIR__) . '/classes/local/management_cycle_acceptance_service.php';
require dirname(__DIR__) . '/classes/local/management_execution_service.php';
require dirname(__DIR__) . '/classes/local/battle_plan_protocol_service.php';

use local_kbacompany\local\management_cycle_acceptance_service as audit;
use local_kbacompany\local\management_execution_service as execution;
use local_kbacompany\local\battle_plan_protocol_service as protocol;

function must(bool $ok, string $name): void {
    if (!$ok) { fwrite(STDERR, "FAIL: $name\n"); exit(2); }
    echo "PASS: $name\n";
}
$company = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$other = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
$week = '2026-10-19';
$end = '2026-10-25';
$base = [
    'week_start'=>$week, 'week_end'=>$end,
    'acceptance'=>['week_start'=>$week,'week_end'=>$end],
    'overview'=>['week_start'=>$week,'review_queue'=>[['plan_uuid'=>'p1']]],
    'summary'=>['needs_decision'=>2,'critical_exceptions'=>1,'active_plans'=>3],
    'decision_loops'=>[],
];
$dashboard = [
    'week_start'=>$week, 'week_end'=>$end,
    'protocol_available'=>false,
    'totals'=>['needs_decision'=>2,'critical_exceptions'=>1,'active_plans'=>3,
        'approval_queue'=>1,'protocol_open'=>null,'protocol_overdue'=>null],
    'items'=>[['kind'=>'plan','url'=>'https://bms24.example/local/kbacompany/battle_plan.php?uuid='.$company.'&plan=p1']],
];
$ok = audit::inspect($company, $base, $dashboard);
must($ok['status'] === 'CONSISTENT_READ_MODELS' && !$ok['warnings'], 'consistent cycle and authorized link');

// The underlying real execution pipeline remains the source of the cycle stages.
$method = new ReflectionMethod(execution::class, 'decision_loops');
$rows = [
    ['management_cycle'=>[]],
    ['management_cycle'=>['action_event_uuid'=>'d1']],
    ['management_cycle'=>['action_event_uuid'=>'d2','task'=>['exists'=>true,'visible'=>true,'status'=>'inprogress']]],
    ['management_cycle'=>['action_event_uuid'=>'d3','task'=>['exists'=>true,'visible'=>true,'status'=>'inprogress','overdue'=>true]]],
    ['management_cycle'=>['action_event_uuid'=>'d4','task'=>['exists'=>true,'visible'=>true,'status'=>'done'],
        'observation'=>['quality'=>'insufficient']]],
    ['management_cycle'=>['action_event_uuid'=>'d5','task'=>['exists'=>true,'visible'=>true,'status'=>'done'],
        'observation'=>['quality'=>'below'], 'requires_new_decision'=>true]],
    ['management_cycle'=>['action_event_uuid'=>'d6','task'=>['exists'=>true,'visible'=>true,'status'=>'done'],
        'observation'=>['quality'=>'improved']]],
];
$loops = $method->invoke(null, $rows);
$stages = array_column($loops, 'stage');
must($stages === ['decision','action','execution','control','followup','repeat','closed'],
    'canonical decision/task/fact/analysis stages (7)');
$withcycles = $base; $withcycles['decision_loops'] = $loops;
$report = audit::inspect($company, $withcycles, $dashboard);
must($report['status'] === 'CONSISTENT_READ_MODELS' && $report['counts']['closed_loops'] === 1 &&
    $report['counts']['awaiting_fact'] === 1 && $report['counts']['repeat_required'] === 1,
    'management cycle counts with no false closure');

$bad = $dashboard; $bad['items'][0]['url'] = '/local/kbacompany/battle_plan.php?uuid='.$other.'&plan=p1';
must(in_array('dashboard_link_outside_company', audit::inspect($company, $base, $bad)['errors'], true),
    'foreign company link blocked by audit');
$bad['items'][0]['url'] = '/local/kbacompany/battle_plan.php?plan=p1';
must(in_array('dashboard_link_outside_company', audit::inspect($company, $base, $bad)['errors'], true),
    'missing company link blocked by audit');
$bad = $dashboard;
$bad['items'][0]['url'] = 'https://evil.example/local/kbacompany/battle_plan.php?uuid='.$company.'&plan=p1';
must(in_array('dashboard_link_outside_company', audit::inspect($company, $base, $bad)['errors'], true),
    'external absolute URL must not be accepted as internal link');
$bad['items'][0]['url'] = 'https://bms24.example.evil/local/kbacompany/battle_plan.php?uuid='.$company;
must(in_array('dashboard_link_outside_company', audit::inspect($company, $base, $bad)['errors'], true),
    'lookalike site origin cannot pass company-link audit');
$bad['items'][0]['url'] = '/local/kbacompany/../outside.php?uuid='.$company;
must(in_array('dashboard_link_outside_company', audit::inspect($company, $base, $bad)['errors'], true),
    'traversal path cannot pass company-link audit');
$bad['items'][0]['url'] = '/local/kbacompany/%2e%2e/outside.php?uuid='.$company;
must(in_array('dashboard_link_outside_company', audit::inspect($company, $base, $bad)['errors'], true),
    'encoded traversal cannot pass company-link audit');
$bad['items'][0]['url'] = '//evil.example/local/kbacompany/battle_plan.php?uuid='.$company;
must(in_array('dashboard_link_outside_company', audit::inspect($company, $base, $bad)['errors'], true),
    'protocol-relative external URL cannot pass company-link audit');
$bad = $base; $bad['acceptance']['week_start'] = '2026-10-12';
must(in_array('week_mismatch_acceptance', audit::inspect($company, $bad, $dashboard)['errors'], true),
    'acceptance and management weeks cannot diverge');
$bad = $dashboard; $bad['totals']['approval_queue'] = 0;
must(in_array('approval_queue_count_mismatch', audit::inspect($company, $base, $bad)['errors'], true),
    'approval count cannot silently diverge from provider');
$bad = $dashboard; $bad['totals']['active_plans'] = 4;
must(in_array('dashboard_counter_mismatch_active_plans', audit::inspect($company, $base, $bad)['errors'], true),
    'plan count cannot silently diverge');
$bad = $dashboard; $bad['totals']['protocol_overdue'] = 0;
must(in_array('restricted_protocol_disclosed_as_count', audit::inspect($company, $base, $bad)['errors'], true),
    'restricted protocol counts remain unknown not zero');
$bad = $withcycles;
$bad['decision_loops'][] = ['stage'=>'execution','task_exists'=>true,'task_visible'=>false,
    'task_url'=>'/local/kbacompany/tasks.php?uuid='.$company.'&task=notvisible'];
must(in_array('restricted_task_link_exposed', audit::inspect($company, $bad, $dashboard)['errors'], true),
    'restricted task must not expose link');
$bad['decision_loops'][7]['task_url'] = '';
$report = audit::inspect($company, $bad, $dashboard);
must($report['status'] === 'CONSISTENT_READ_MODELS' && $report['counts']['restricted_tasks'] === 1,
    'restricted task may be counted without sensitive link');
$bad = $dashboard; $bad['items'][0]['url']='';
must(audit::inspect($company, $base, $bad)['warnings'] === ['dashboard_object_without_link'],
    'missing authorized link is a warning, not fabricated evidence');
$bad = $base; $bad['week_end'] = '2026-10-26';
must(in_array('invalid_management_week', audit::inspect($company, $bad, $dashboard)['errors'], true),
    'week date boundaries are verified');

must(protocol::valid_iso_date('2028-02-29') && protocol::valid_iso_date('2026-10-24') &&
    !protocol::valid_iso_date('2026-02-29') && !protocol::valid_iso_date('2026-02-31') &&
    !protocol::valid_iso_date('2026-13-01') && !protocol::valid_iso_date('2026-1-01'),
    'decision assignment rejects impossible due dates');
echo "Management-cycle acceptance tests PASS\n";
