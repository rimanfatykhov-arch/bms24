<?php
// Run independently of Moodle DB; tests pure read-only summary rules.
define('MOODLE_INTERNAL', true);
require_once(__DIR__ . '/../classes/local/battle_plan_approval_queue_service.php');
use local_kbacompany\local\battle_plan_approval_queue_service as Queue;
$assert = static function($condition, string $message): void {
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
};
$review = static function(int $id, string $item = 'task1'): stdClass {
    return (object)['id'=>$id,'decision'=>'changes','timecreated'=>100,'actorid'=>4,
        'comment'=>'Fix it','itemuuid'=>$item];
};
$empty = Queue::correction_counts([], []);
$assert($empty['total']===0,'empty');
$rows=[$review(2,'task2'),$review(1,'task1')];
$events=['review_correction_events'=>[
    ['batch'=>'100:4:Fix it','target'=>'task1','action'=>'claimed'],
    ['batch'=>'100:4:Fix it','target'=>'task1','action'=>'accepted'],
    ['batch'=>'100:4:Fix it','target'=>'task2','action'=>'rework']]];
$counts=Queue::correction_counts($rows,$events);
$assert($counts['total']===2 && $counts['accepted']===1 && $counts['pending']===1,'latest corrections');
$approved = Queue::correction_counts([(object)['decision'=>'approved','itemuuid'=>'']], $events);
$assert($approved['total']===0,'approved batch');
$assessment=Queue::assessment(['reviewer_id'=>7, 'reviewer_valid'=>true, 'tasks'=>['total'=>3],
    'performance'=>['metric_total'=>5,'metric_evaluated'=>3]],$counts);
$assert($assessment['metrics_missing']===2 && $assessment['outstanding']===1 && $assessment['advisory'],'advisory');
$cleared=Queue::assessment(['reviewer_id'=>1,'reviewer_valid'=>true,'tasks'=>['total'=>3],
    'performance'=>['metric_total'=>5,'metric_evaluated'=>5]],
    ['total'=>1,'accepted'=>1,'pending'=>0,'claimed'=>0]);
$assert(!$cleared['advisory'],'cleared');
$invalid=Queue::assessment(['reviewer_id'=>5,'reviewer_valid'=>false,'tasks'=>['total'=>1],
    'performance'=>['metric_total'=>1,'metric_evaluated'=>1]],['total'=>0,'pending'=>0,'claimed'=>0]);
$assert($invalid['advisory'] && !$invalid['reviewer_assigned'],'invalid reviewer advisory');
echo "approval queue projection PASS\n";
