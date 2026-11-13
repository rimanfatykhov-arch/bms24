<?php
// Pure projection tests; no database or Moodle bootstrap required.
define('MOODLE_INTERNAL', true);
require_once(__DIR__ . '/../classes/local/management_leader_dashboard_service.php');
use local_kbacompany\local\management_leader_dashboard_service as Dashboard;
function verify_dashboard($got, $expected, string $case): void {
    if ($got !== $expected) {
        throw new RuntimeException($case . ': expected ' . var_export($expected, true) .
            ', got ' . var_export($got, true));
    }
}
$base = [
    'week_start'=>'2026-10-19', 'week_end'=>'2026-10-25',
    'summary'=>['needs_decision'=>2, 'critical_exceptions'=>1, 'active_plans'=>28],
    'exceptions'=>['rows'=>[
        ['severity'=>'critical','metric'=>['name'=>'Revenue'],'context_label'=>'Sales',
            'management_cycle'=>['status'=>'needs_decision'],'dashboard_url'=>'/indicators'],
        ['severity'=>'medium','metric'=>['name'=>'Routine'],'context_label'=>'Ops',
            'management_cycle'=>['status'=>'clear'],'dashboard_url'=>'/indicators'],
    ]],
    'overview'=>['review_queue'=>[
        ['employee_name'=>'Team lead','orgnode'=>['name'=>'Ops'],'url'=>'/bplan/1'],
        ['employee_name'=>'Sales lead','orgnode'=>['name'=>'Sales'],'url'=>'/bplan/2'],
    ]],
    'acceptance'=>['state'=>'blocked'],
];
$tasks = ['counts'=>['total'=>29], 'tasks'=>[
    ['title'=>'Overdue report','assignee_name'=>'Editor','status'=>'doing',
        'due_date'=>'2026-10-22','dashboard_url'=>'/task/1'],
    ['title'=>'Done report','assignee_name'=>'Editor','status'=>'done',
        'due_date'=>'2026-10-22','dashboard_url'=>'/task/2'],
    ['title'=>'Due today','assignee_name'=>'Editor','status'=>'open',
        'due_date'=>'2026-10-24','dashboard_url'=>'/task/3'],
]];
$protocol = [
    ['title'=>'Decision A','employee'=>'A','status'=>'pending', 'due'=>'2026-10-21','url'=>'/coord/a'],
    ['title'=>'Decision B','employee'=>'B','status'=>'inprogress','due'=>'2026-10-24','url'=>'/coord/b'],
    ['title'=>'Decision C','employee'=>'C','status'=>'completed','due'=>'2026-10-20','url'=>'/coord/c'],
    ['title'=>'Decision D','employee'=>'D','status'=>'inprogress','due'=>'', 'url'=>'/coord/d'],
];
$p = Dashboard::project($base, $tasks, $protocol, true, '2026-10-24');
verify_dashboard($p['totals']['approval_queue'], 2, 'plan approval count');
verify_dashboard($p['totals']['overdue_tasks'], 29, 'canonical full SQL count, not paginated');
verify_dashboard($p['totals']['protocol_open'], 3, 'open latest protocol decisions');
verify_dashboard($p['totals']['protocol_overdue'], 1, 'overdue protocol, no current day');
verify_dashboard($p['totals']['needs_decision'], 2, 'use canonical exception summary');
verify_dashboard(array_column($p['items'], 'kind'),
    ['exception','plan','plan','protocol','task'], 'relevance order and skip unflagged/done');
verify_dashboard($p['items'][3]['url'], '/coord/a', 'link to canonical decision');
verify_dashboard($p['has_more'], true, 'SQL tasks exceed first visible page');
verify_dashboard($p['week_start'], '2026-10-19', 'keep selected plan week');
verify_dashboard($p['acceptance']['state'], 'blocked', 'original gate status');

$limited = Dashboard::project($base, $tasks, $protocol, true, '2026-10-24', 2);
verify_dashboard(count($limited['items']), 2, 'bounded UI queue');
verify_dashboard($limited['totals'], $p['totals'], 'UI limit never affects aggregates');
$restricted = Dashboard::project($base, $tasks, $protocol, false, '2026-10-24');
verify_dashboard($restricted['totals']['protocol_open'], null, 'no permissions is unknown not zero');
verify_dashboard($restricted['totals']['protocol_overdue'], null, 'no permissions is unknown not zero');
verify_dashboard(in_array('protocol',array_column($restricted['items'],'kind'),true),
    false,'no unauthorized protocol details');
$clean = Dashboard::project(['summary'=>[], 'exceptions'=>['rows'=>[]],
    'overview'=>['review_queue'=>[]]], ['counts'=>['total'=>0], 'tasks'=>[]], [], true, '2026-10-24');
verify_dashboard(count($clean['items']), 0, 'no signals');
verify_dashboard($clean['totals']['protocol_open'], 0, 'authorized empty protocol zero');
verify_dashboard($clean['has_more'], false, 'empty queue no overflow');
// Different companies are enforced by canonical providers, not by merging models here.
// This assertion protects against accidentally concatenating arbitrary source data.
verify_dashboard(Dashboard::contract()['storage'], 'none', 'read-only service');
echo "Manager dashboard projection PASS\n";
