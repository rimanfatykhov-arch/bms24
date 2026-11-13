<?php
// Pure-contract smoke, no Moodle DB. Final permission checks remain in Moodle services.
define('MOODLE_INTERNAL', true);
require __DIR__ . '/../classes/local/implementation_battle_plan_link_service.php';
use local_kbacompany\local\implementation_battle_plan_link_service as S;
$checks = 0;
$check = function(bool $pass, string $name) use (&$checks): void {
    if (!$pass) { throw new RuntimeException('FAIL: '.$name); }
    $checks++;
};
$task = ['provider'=>'kbacorporate_impl', 'corporate_company_id'=>12,
    'assignee_id'=>5, 'attachable'=>true, 'status'=>'open'];
$check(S::eligible_task($task,12,5),'own open task');
$task['status']='doing';
$check(S::eligible_task($task,12,5),'in progress');
$check(!S::eligible_task($task,13,5),'another corporate company');
$check(!S::eligible_task($task,12,6),'another employee');
$check(!S::eligible_task(null,12,5),'absent task');
foreach (['review','done','cancelled','unknown'] as $status) {
    $t=$task; $t['status']=$status;
    $check(!S::eligible_task($t,12,5),'deny status '.$status);
}
$t=$task; $t['attachable']=false;
$check(!S::eligible_task($t,12,5),'paused task');
$t=$task; $t['provider']='bms';
$check(!S::eligible_task($t,12,5),'another provider');
$t=$task; unset($t['corporate_company_id']);
$check(!S::eligible_task($t,12,5),'unverified mapping');
$uuid1='123e4567-e89b-42d3-a456-426614174000';
$uuid2='123e4567-e89b-42d3-a456-426614174001';
$drafts=[$uuid1=>['uuid'=>$uuid1,'user_id'=>5,'label'=>'Week A'],
    $uuid2=>['uuid'=>$uuid2,'user_id'=>6,'label'=>'Week B'],
    'unsafe'=>['uuid'=>'javascript:','user_id'=>5,'label'=>'Unsafe']];
$out=S::select_drafts($drafts,5);
$check(count($out)===1 && isset($out[$uuid1]), 'only matching assignee valid UUID');
$check(S::select_drafts($drafts,8)===[], 'no plan for another actor');
echo "PASS implementation attach selection: $checks checks\n";
