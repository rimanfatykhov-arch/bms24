<?php
// Standalone projection unit tests: no Moodle installation, PHP 8.1-compatible.
define('MOODLE_INTERNAL', true);
require_once dirname(__DIR__) . '/classes/local/implementation_program_task_sections_projection.php';
use local_kbacorporate\local\implementation_program_task_sections_projection as P;
$count=0;
$ok=static function($assertion,$name) use (&$count){$count++;if(!$assertion){throw new RuntimeException($name);}};
$record=static function($id,$company,$audit,$section,$status,$owner=3){return (object)[
    'id'=>$id, 'companyid'=>$company,'auditid'=>$audit,'sectioncode'=>$section,
    'status'=>$status,'title'=>'Test task '.$id, 'ownerid'=>$owner,'duedate'=>0, 'secret'=>'restricted'];};
$source=[ $record(11,7,22,'A1','planned'),$record(12,7,22,'','inprogress'),
    $record(13,7,22,'A1','review'),$record(14,7,22,'A2','completed'),
    $record(15,7,22,'A2','cancelled'),$record(16,8,22,'A2','planned'),
    $record(17,7,23,'A1','planned'),$record(11,7,22,'A1','planned'),
    $record(18,7,22,'A1','secretstatus') ];
$r=P::project_rows($source,7,5,22);
$ok($r['company_id']===7&&$r['program_id']===5&&$r['audit_id']===22,'identity');
$ok($r['complete']===true,'complete');
$ok(count($r['tasks'])===5,'same company & audit; unique ids & allowed statuses');
$ok($r['tasks'][1]['section_code']==='general','fallback section');
$ok($r['tasks'][2]['status']==='review','review distinct');
$ok($r['tasks'][3]['status']==='completed','completed distinct');
$ok($r['tasks'][4]['status']==='cancelled','cancelled retained');
$ok($r['tasks'][0]['external_id']==='impltask:11','source identity');
$ok(!array_key_exists('secret',$r['tasks'][0]),'no secrets');
$ok(!array_key_exists('companyid',$r['tasks'][0]),'minimal task projection');
$truncated=P::project_rows($source,7,5,22,false);
$ok($truncated['complete']===false&&!$truncated['tasks'],'fail closed incomplete');
$ok($truncated['reason']==='limit_exceeded','incomplete reason');
echo "PASS Corporate task-section projection: $count assertions\n";
