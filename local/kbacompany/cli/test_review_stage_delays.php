<?php
// Deterministic event-projection QA; no Moodle server required.
define('MOODLE_INTERNAL', true);
require_once(__DIR__ . '/../classes/local/battle_plan_review_cycle_service.php');
require_once(__DIR__ . '/../classes/local/battle_plan_review_stage_delay_service.php');
use local_kbacompany\local\battle_plan_review_cycle_service as Events;
use local_kbacompany\local\battle_plan_review_stage_delay_service as Stage;
function expect_stage($value, $expected, string $case): void {
    if ($value !== $expected) {
        throw new RuntimeException($case . ': expected ' . var_export($expected, true) .
            ', got ' . var_export($value, true));
    }
}
function events(array $steps): string {
    $raw = '';
    foreach ($steps as $step) { $raw = Events::append_event($raw, $step[0], $step[1], $step[2]); }
    return $raw;
}
function plan(string $id, string $payload, string $status, string $company='c1',
        string $unit='sales', int $employee=1, int $reviewer=2): array {
    return ['plan_uuid'=>$id, 'event_payload'=>$payload, 'status'=>$status,
        'companyuuid'=>$company, 'unit_uuid'=>$unit, 'unit_name'=>$unit,
        'employee_id'=>$employee, 'employee_name'=>'Employee ' . $employee,
        'reviewer_id'=>$reviewer, 'reviewer_name'=>'Reviewer ' . $reviewer];
}
$now = 12000;
// 1. Ordinary approval.
$a = Stage::project_plan(events([['submitted',1,1000],['approved',2,2000]]), 'active', $now);
expect_stage(count($a['manager']), 1, 'ordinary manager segment');
expect_stage($a['manager'][0]['seconds'], 1000, 'ordinary duration');
expect_stage(count($a['employee']), 0, 'no employee duration');
// 2. Single return and resubmission (ongoing review).
$twice = events([['submitted',1,1000],['returned',2,2000],['submitted',1,3000]]);
$b = Stage::project_plan($twice, 'submitted', $now);
expect_stage($b['manager'][0]['seconds'], 1000, 'return decision');
expect_stage($b['employee'][0]['seconds'], 1000, 'rework');
expect_stage($b['open']['seconds'], 9000, 'current reviewer wait');
// 3. Multiple returns and final approval, all stages counted once.
$multi = events([['submitted',1,1000],['returned',2,2000],['submitted',1,2400],
    ['returned',2,3100],['submitted',1,3700],['approved',2,4900]]);
$c = Stage::project_plan($multi, 'active', $now);
expect_stage(array_column($c['manager'], 'seconds'), [1000,700,1200], 'three manager stages');
expect_stage(array_column($c['employee'], 'seconds'), [400,600], 'two employee stages');
expect_stage($c['open'], null, 'no active stage after approval');
// 4. Open rework, and disagreement with saved status.
$openrework = events([['submitted',1,1000],['returned',2,1700]]);
$d = Stage::project_plan($openrework, 'draft', $now);
expect_stage($d['open']['stage'], 'employee', 'ongoing rework');
expect_stage($d['open']['seconds'], 10300, 'ongoing rework time');
expect_stage(Stage::project_plan($openrework, 'active', $now)['open'], null, 'no fabricated open stage');
// 5. Self approval and events without an initial submission.
$self = Stage::project_plan(events([['selfapproved',1,1500]]), 'active', $now);
expect_stage($self['selfapproved'], 1, 'self-approval excluded');
expect_stage(count($self['manager']), 0, 'self-approval not a manager wait');
$orphan = Stage::project_plan(events([['returned',2,2000],['submitted',1,3000],
    ['approved',2,4500]]), 'active', $now);
expect_stage($orphan['invalid'], 1, 'missing matching submission flagged');
expect_stage($orphan['manager'][0]['seconds'], 1500, 'next valid cycle still usable');
expect_stage(count($orphan['employee']), 0, 'do not infer old rework');
expect_stage(Stage::project_plan('', 'submitted', $now)['open'], null, 'missing events not inferred');
// 6. Same-second transitions legitimately create zero-length durations.
$zero = Stage::project_plan(events([['submitted',1,1000],['returned',2,1000],
    ['submitted',1,1000],['approved',2,1000]]), 'active', $now);
expect_stage(array_column($zero['manager'], 'seconds'), [0,0], 'zero durations');
expect_stage($zero['employee'][0]['seconds'], 0, 'zero rework');
// 7. Clock reversal, future timestamp and broken transitions are rejected.
$clock = Stage::project_plan(events([['submitted',1,5000],['approved',2,4000],
    ['submitted',1,6000],['approved',2,7000]]), 'active', $now);
expect_stage(count($clock['manager']), 1, 'no duration through reversed time');
expect_stage($clock['manager'][0]['seconds'], 1000, 'valid later segment');
expect_stage($clock['invalid'], 1, 'clock reversal visible in coverage');
expect_stage(Stage::project_plan(events([['submitted',1,13000]]), 'submitted', $now)['open'],
    null, 'future submit excluded');
// 8. Duplicate event copied into JSON is ignored, not counted twice.
$raw = json_decode(events([['submitted',1,1000],['returned',2,2000],
    ['submitted',1,3000],['approved',2,4000]]), true);
array_splice($raw['review_cycle_events'], 2, 0, [$raw['review_cycle_events'][1]]);
$duplicate = Stage::project_plan(json_encode($raw), 'active', $now);
expect_stage($duplicate['duplicate'], 1, 'exact duplicate detected');
expect_stage(count($duplicate['manager']), 2, 'no double count of manager');
// 9. Aggregation runs over the entire supplied authorized set, independent of UI slicing.
$dataset = [plan('a',events([['submitted',1,1000],['approved',2,2000]]),'active','a','sales',1,2),
    plan('b',$twice,'submitted','a','sales',3,2),
    plan('c',$openrework,'draft','a','ops',5,4),
    plan('d',events([['selfapproved',1,1500]]),'active','a','ops',6,6),
    plan('e','','draft','a','ops',7,4)];
$summary = Stage::summarize($dataset, $now);
expect_stage($summary['counts']['plans'], 5, 'all plans');
expect_stage($summary['counts']['manager_cycles'], 3, 'closed manager segments');
expect_stage($summary['counts']['employee_cycles'], 1, 'closed rework segments');
expect_stage($summary['counts']['manager_open'], 1, 'one reviewer wait');
expect_stage($summary['counts']['employee_open'], 1, 'one rework');
expect_stage($summary['manager_average'], 900, 'mean of completed manager stages only');
expect_stage($summary['employee_average'], 1000, 'mean of completed employee rework only');
expect_stage($summary['counts']['recorded'], 4, 'recorded coverage');
expect_stage($summary['counts']['evidenced'], 3, 'measurable coverage');
expect_stage(count(array_slice($summary['rows'], 0, 2)), 2, 'UI slice');
expect_stage($summary['counts']['plans'], 5, 'aggregation unaffected by UI slice');
expect_stage(array_sum(array_column($summary['units'], 'manager_cycles')), 3, 'group totals');
// Company boundary is enforced by the database context, NOT by this projection.
// Simulate a previously ACL-filtered collection: the foreign company must not appear.
$authorized = array_values(array_filter(array_merge($dataset, [plan('foreign', $multi, 'active', 'b')]),
    static function(array $p): bool { return $p['companyuuid'] === 'a'; }));
expect_stage(Stage::summarize($authorized, $now)['counts']['plans'], 5, 'authorized rows only');
// Do not claim this stand-alone test verifies the Moodle ACL implementation.
echo "review stage delay projection test PASS\n";
