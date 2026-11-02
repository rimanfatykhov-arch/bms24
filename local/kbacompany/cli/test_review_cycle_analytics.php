<?php
// Pure analytics projections without Moodle bootstrap.
define('MOODLE_INTERNAL', true);
require_once(__DIR__ . '/../classes/local/battle_plan_review_cycle_service.php');
require_once(__DIR__ . '/../classes/local/battle_plan_review_cycle_analytics_service.php');
use local_kbacompany\local\battle_plan_review_cycle_service as Events;
use local_kbacompany\local\battle_plan_review_cycle_analytics_service as Analytics;
function ok($condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
function p(string $uuid, string $payload, string $unit, int $employee, int $reviewer): array {
    return ['plan_uuid'=>$uuid, 'event_payload'=>$payload, 'unit_uuid'=>$unit,
        'unit_name'=>'Unit ' . $unit, 'employee_id'=>$employee,
        'employee_name'=>'Employee ' . $employee, 'reviewer_id'=>$reviewer,
        'reviewer_name'=>'Reviewer ' . $reviewer];
}
$first = Events::append_event('', 'submitted', 1, 1000);
$first = Events::append_event($first, 'returned', 2, 4600);
$first = Events::append_event($first, 'submitted', 1, 5000);
$first = Events::append_event($first, 'approved', 2, 6800);
$second = Events::append_event('', 'submitted', 3, 2000);
$second = Events::append_event($second, 'returned', 4, 2600);
$second = Events::append_event($second, 'submitted', 3, 3000);
$second = Events::append_event($second, 'returned', 4, 4200);
$self = Events::append_event('', 'selfapproved', 5, 1500);
$legacy = Events::append_event('', 'approved', 4, 6400); // Missing submission: not measurable.
$res = Analytics::summarize([
    p('a',$first,'sales',1,2), p('b',$second,'sales',3,4),
    p('c',$self,'ops',5,5), p('d',$legacy,'ops',6,4), p('e','', 'ops',7,2),
]);
$c = $res['counts'];
ok($c['plans'] === 5 && $c['tracked'] === 4 && $c['untracked'] === 1, 'coverage');
ok($c['recorded_submissions'] === 4 && $c['recorded_returns'] === 3, 'submissions and returns');
ok($c['recorded_approvals'] === 2 && $c['selfapprovals'] === 1, 'decisions');
ok($c['measured_decisions'] === 4 && $res['average_seconds'] === 1800, 'measured time excludes unknown and self');
ok($c['repeated_submissions'] === 2 && $c['repeated_returns'] === 1, 'repeat plans');
ok($res['reviewers'][0]['id'] === '4' && $res['reviewers'][0]['returns'] === 2, 'assigned reviewer grouped');
ok($res['units'][0]['id'] === 'sales' && $res['units'][0]['returns'] === 3, 'unit grouped');
ok($res['repeated_total'] === 2 && count($res['repeated']) === 2, 'repeated drilldown');
ok(Analytics::summarize([])['average_seconds'] === null, 'empty period not 0');
ok(Analytics::summarize([p('old','{"legacy":true}','ops',1,2)])['counts']['tracked'] === 0, 'no invented old history');
echo "review cycle analytics test PASS\n";
