<?php
// Corporate 0.40.8 evidence result API: pure fixtures; no live Moodle database.
define('MOODLE_INTERNAL', true);
require_once dirname(__DIR__) . '/classes/local/implementation_program_result_projection.php';
use local_kbacorporate\local\implementation_program_result_projection as R;
$n = 0;
$ok = static function(bool $value, string $message) use (&$n): void {
    $n++;
    if (!$value) { throw new RuntimeException($message); }
};
$cases = [
    ['planned','none','not_started'], ['inprogress','none','in_work'],
    ['review','submitted','awaiting_review'], ['review','none','review_without_evidence'],
    ['inprogress','rejected','returned'], ['completed','accepted','accepted'],
    ['completed','none','completed_unverified'], ['completed','submitted','inconsistent'],
    ['review','rejected','inconsistent'], ['planned','accepted','inconsistent'],
    ['inprogress','submitted','inconsistent'], ['cancelled','submitted','cancelled'],
];
foreach ($cases as [$taskstatus,$evidencestatus,$expected]) {
    $ok(R::state($taskstatus,$evidencestatus)===$expected,$taskstatus.'/'.$evidencestatus);
}
$tasks = [];
foreach ($cases as $i => $row) {
    $tasks[]=['external_id'=>'impltask:'.($i+1), 'section_code'=>'A', 'status'=>$row[0]];
}
$evidence=[];
foreach ($cases as $i => $row) {
    if ($row[1]==='none') {continue;}
    $evidence[$i+1]=(object)['status'=>$row[1],'versionno'=>2,'submittedat'=>10,'reviewedat'=>20,
        'summary'=>'TOP SECRET','reviewcomment'=>'TOP SECRET','filename'=>'private.pdf'];
}
$projected=R::project_rows($tasks,$evidence,7,11,23);
$ok($projected['complete'] && $projected['company_id']===7 && $projected['audit_id']===23,'identities');
$ok(count($projected['tasks'])===count($cases),'all tasks projected');
foreach ($projected['tasks'] as $i=>$row) {
    $ok($row['result_state']===$cases[$i][2], 'projected state '.$i);
    $ok(!isset($row['summary'])&&!isset($row['reviewcomment'])&&!isset($row['filename']), 'no PII '.$i);
    $ok(!isset($row['reviewerid'])&&!isset($row['evidence_url']), 'no personal reviewer data '.$i);
}
$tasks[]=$tasks[0];
$projected=R::project_rows($tasks,$evidence,7,11,23);
$ok(count($projected['tasks'])===count($cases),'duplicate task omitted');
$ok($projected['tasks'][0]['evidence_version']===0,'no evidence is not version 1');
$ok($projected['tasks'][2]['reviewed_at']===20,'review timestamps safe');
echo "PASS Corporate evidence decision projection: $n assertions\n";
