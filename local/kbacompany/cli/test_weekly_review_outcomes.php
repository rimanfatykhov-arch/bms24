<?php
// Unit-style projection tests without a Moodle bootstrap.
define('MOODLE_INTERNAL', true);
require_once(__DIR__ . '/../classes/local/battle_plan_weekly_review_outcome_service.php');
use local_kbacompany\local\battle_plan_weekly_review_outcome_service as Outcome;
function p(string $uuid,string $status,string $unit,int $reviewer): array {
 return ['plan_uuid'=>$uuid,'status'=>$status,'unit_uuid'=>$unit,'unit_name'=>'Unit ' . $unit,
         'reviewer_id'=>$reviewer,'reviewer_name'=>'Reviewer ' . $reviewer];
}
$plans = [p('a','submitted','x',3),p('b','draft','x',3),p('c','active','y',4),
          p('d','closed','y',4),p('e','draft','y',0),p('f','active','x',3)];
$events = ['a'=>'changes','b'=>'changes','c'=>'approved','d'=>'approved','f'=>'changes'];
$summary=Outcome::summarize($plans,$events);
assert($summary['counts']['all']===6);
assert($summary['counts']['waiting']===1);
assert($summary['counts']['returned']===1);
assert($summary['counts']['approved']===2);
assert($summary['counts']['other']===2);
assert(array_sum(array_intersect_key($summary['counts'], array_flip(Outcome::OUTCOMES)))===6);
assert(count($summary['units'])===2 && count($summary['reviewers'])===3);
foreach (array_merge($summary['units'],$summary['reviewers']) as $group) {
 assert($group['all'] === $group['approved'] + $group['returned'] + $group['waiting'] + $group['other']);
}
assert(Outcome::summarize([], [])['counts']['all'] === 0);
// An older return event must never turn a resubmitted plan into "returned".
assert(Outcome::summarize([p('a','submitted','x',3)], ['a'=>'changes'])['counts']['returned'] === 0);
// A legacy active plan with no approved review entry must not be labelled approved.
assert(Outcome::summarize([p('z','active','x',3)], [])['counts']['approved'] === 0);
echo "weekly approval outcome projection PASS\n";
