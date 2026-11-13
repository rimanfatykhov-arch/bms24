<?php
// Pure dashboard aggregation tests; no Moodle bootstrap or DB required.
define('MOODLE_INTERNAL', true);
require_once dirname(__DIR__) . '/classes/local/implementation_project_dashboard_service.php';
use local_kbacompany\local\implementation_project_dashboard_service as D;
$n = 0;
$assert = static function($condition, $name) use (&$n): void {
    $n++;
    if (!$condition) { throw new RuntimeException($name); }
};
$base = [
    'stages' => ['available'=>true, 'summary'=>['tasks'=>9,'overdue'=>2],
        'sections'=>[['code'=>'A','name'=>'Цели','task_count'=>4,'overdue_count'=>1],
            ['code'=>'B','name'=>'Процессы','task_count'=>5,'overdue_count'=>1]]],
    'weekly_complete'=>true,
    'weekly_sections'=>[
        'A'=>['planned'=>2,'unplanned'=>1], 'B'=>['planned'=>1,'unplanned'=>3],
    ],
    'result_complete'=>true,
    'result_sections'=>[
        'A'=>['counts'=>['awaiting_review'=>1,'review_without_evidence'=>0,'returned'=>1,
            'accepted'=>1,'completed_unverified'=>1,'inconsistent'=>0],
            'tasks'=>[['external_id'=>'impltask:20','result_state'=>'returned'],
                ['external_id'=>'impltask:21','result_state'=>'accepted'],
                ['external_id'=>'impltask:22','result_state'=>'awaiting_review']]],
        'B'=>['counts'=>['awaiting_review'=>0,'review_without_evidence'=>1,'returned'=>1,
            'accepted'=>2,'completed_unverified'=>0,'inconsistent'=>1],
            'tasks'=>[['external_id'=>'impltask:23','result_state'=>'returned'],
                ['external_id'=>'impltask:24','result_state'=>'completed_unverified'],
                ['external_id'=>'impltask:25','result_state'=>'inconsistent']]],
    ],
];
$out=D::summarize($base);
$assert($out['stage_available'] && $out['week_complete'] && $out['result_complete'], 'all available');
$assert($out['counts']['tasks']===9 && $out['counts']['stages']===2, 'summary');
$assert($out['counts']['planned']===3 && $out['counts']['unplanned']===4, 'one week');
$assert($out['counts']['awaiting_review']===2, 'both review kinds');
$assert($out['counts']['returned']===2 && $out['counts']['accepted']===3, 'accepted separate from returned');
$assert($out['counts']['unverified']===1 && $out['counts']['inconsistent']===1, 'unsafe statuses separate');
$assert($out['attention_total']===5, 'only unresolved results');
$assert($out['attention'][0]['state']==='returned', 'returned priority');
$assert($out['sections']['A']['planned']===2 && $out['sections']['B']['accepted']===2, 'per section');
$restricted=$base;$restricted['weekly_complete']=false;$restricted['weekly_sections']=[];
$x=D::summarize($restricted);
$assert($x['counts']['planned']===null && $x['counts']['accepted']===3, 'no fake zero without week rights');
$incomplete=$base;$incomplete['result_complete']=false;$incomplete['result_sections']=[];
$x=D::summarize($incomplete);
$assert($x['counts']['accepted']===null && !$x['attention'], 'no fake results');
$empty=$base;$empty['stages']['available']=false;
$x=D::summarize($empty);
$assert($x['counts']['tasks']===null && $x['counts']['stages']===null, 'no baseline means unknown');
$missing=$base;unset($missing['weekly_sections']['A']);
try { D::summarize($missing);$assert(false, 'missing weekly fail closed'); }
catch (UnexpectedValueException $e) {$assert(true, 'missing weekly fail closed');}
$duplicate=$base;$duplicate['stages']['sections'][1]['code']='A';
try { D::summarize($duplicate);$assert(false, 'duplicate fail closed'); }
catch (UnexpectedValueException $e) {$assert(true, 'duplicate fail closed');}
$missing=$base;unset($missing['result_sections']['B']);
try { D::summarize($missing);$assert(false, 'missing result fail closed'); }
catch (UnexpectedValueException $e) {$assert(true, 'missing result fail closed');}
$withMany=$base;
$withMany['result_sections']['B']['tasks']=[];
for($i=100;$i<125;$i++) { $withMany['result_sections']['B']['tasks'][]=['external_id'=>'impltask:'.$i,'result_state'=>'returned']; }
$x=D::summarize($withMany);
$assert($x['attention_total']===27 && count($x['attention'])===12,'queue capped without truncating total');
echo "PASS implementation cockpit: $n assertions\n";
