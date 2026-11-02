<?php
// Local pure unit cases for the BMS results overlay (no Moodle DB).
define('MOODLE_INTERNAL', true);
require_once dirname(__DIR__) . '/classes/local/implementation_project_result_service.php';
use local_kbacompany\local\implementation_project_result_service as R;
$n = 0;
$ok = static function(bool $condition, string $description) use (&$n): void {
    $n++;
    if (!$condition) {throw new RuntimeException($description);}
};
$sections=[['code'=>'A'],['code'=>'B']];
$task = static function($id,$code,$status,$result): array {
    return ['external_id'=>'impltask:'.$id, 'section_code'=>$code, 'task_status'=>$status,
        'result_state'=>$result, 'evidence_version'=>1,'submitted_at'=>30, 'reviewed_at'=>50];
};
$rows=[$task(1,'A','inprogress','returned'),$task(2,'A','review','awaiting_review'),
    $task(3,'A','completed','accepted'),$task(4,'B','completed','completed_unverified'),
    $task(5,'B','inprogress','in_work'),$task(6,'B','cancelled','cancelled')];
$weekly=['A'=>['tasks'=>[
    ['external_id'=>'impltask:1','status'=>'inprogress','plan_links'=>[['plan_uuid'=>'x','owner_id'=>2]]],
    ['external_id'=>'impltask:2','status'=>'review','plan_links'=>[]],
    ['external_id'=>'impltask:3','status'=>'completed','plan_links'=>[]]]],
    'B'=>['tasks'=>[
    ['external_id'=>'impltask:4','status'=>'completed','plan_links'=>[]],
    ['external_id'=>'impltask:5','status'=>'inprogress','plan_links'=>[]],
    ['external_id'=>'impltask:6','status'=>'cancelled','plan_links'=>[]]]]];
$res=R::summarize($sections,$rows,$weekly,true);
$ok($res['A']['counts']['returned']===1,'returned');
$ok($res['A']['counts']['awaiting_review']===1,'pending review');
$ok($res['A']['counts']['accepted']===1,'accepted only by curator');
$ok($res['B']['counts']['completed_unverified']===1,'completed not accepted');
$ok($res['B']['counts']['in_work']===1,'in progress');
$ok($res['B']['counts']['cancelled']===1,'cancelled');
$ok(count($res['A']['tasks'][0]['plan_links'])===1,'validated plan link reused');
$ok($res['B']['tasks'][1]['plan_links']===[],'no plan');
$other=R::summarize($sections,$rows,[],false);
$ok($other['A']['counts']['accepted']===1,'result works without access to company-wide plans');
$testFailure=static function(array $items,array $links) use ($sections,$ok): void {
    try { R::summarize($sections,$items,$links,true); }
    catch (UnexpectedValueException $e) { $ok(true,'invalid snapshot blocked'); return; }
    $ok(false,'unexpected reconciliation success');
};
$changed=$rows;$changed[0]['task_status']='completed';$testFailure($changed,$weekly);
$missing=$rows;array_pop($missing);$testFailure($missing,$weekly);
$duplicate=$rows;$duplicate[]=$rows[0];$testFailure($duplicate,$weekly);
$invalid=$rows;$invalid[0]['result_state']='malicious';$testFailure($invalid,$weekly);
$conflict=$rows;$conflict[0]['result_state']='accepted';$testFailure($conflict,$weekly);
$ok(count($res)===2,'no unknown sections');
echo "PASS BMS curator result overview: $n assertions\n";
