<?php
// Standalone pure weekly aggregation tests. Moodle DB/ACL exercised only in target environment.
define('MOODLE_INTERNAL', true);
require_once dirname(__DIR__) . '/classes/local/implementation_project_weekly_service.php';
use local_kbacompany\local\implementation_project_weekly_service as W;
$n=0;
$ok=static function($condition,$message) use (&$n){$n++;if(!$condition){throw new RuntimeException($message);}};
$sections=[['code'=>'A1'],['code'=>'A2']];
$task=static function($id,$code,$status,$owner=20) {return [
    'external_id'=>'impltask:'.$id,'section_code'=>$code,'status'=>$status,
    'title'=>'Task '.$id,'assignee_id'=>$owner];};
$source=[$task(1,'A1','planned'),$task(2,'A1','inprogress'),$task(3,'A1','review'),
    $task(4,'A1','completed'),$task(5,'A1','cancelled'),$task(6,'A2','planned'),
    $task(6,'A2','planned'),$task(7,'other','planned'),
    ['external_id'=>'bad-id','section_code'=>'A1','status'=>'planned','assignee_id'=>20]];
$link=['plan_uuid'=>'a','owner_id'=>20,'status'=>'draft'];
$links=['impltask:1'=>['a'=>$link,'b'=>array_merge($link,['plan_uuid'=>'b','status'=>'active'])],
   'impltask:2'=>['c'=>array_merge($link,['owner_id'=>21])],
   'impltask:6'=>['d'=>$link]];
$r=W::summarize($sections,$source,$links);
$ok(count($r)===2,'sections');
$ok($r['A1']['planned']===1,'multiple plan refs counted once');
$ok($r['A1']['unplanned']===1,'foreign owner plan excluded');
$ok($r['A1']['review']===1,'review separate');
$ok($r['A1']['completed']===1,'completed separate');
$ok($r['A1']['cancelled']===1,'cancelled separate');
$ok($r['A2']['planned']===1,'second section count');
$ok($r['A2']['unplanned']===0,'duplicate Corporate task not counted');
$ok(count($r['A2']['tasks'])===1,'duplicate id removed');
$ok(count($r['A1']['tasks'][0]['plan_links'])===2,'retain both weeks same period links');
$ok(count($r['A1']['tasks'][1]['plan_links'])===0,'wrong assignee filtered');
$ok(!isset($r['other']),'unknown section ignored');
echo "PASS BMS weekly implementation summarizer: $n assertions\n";
