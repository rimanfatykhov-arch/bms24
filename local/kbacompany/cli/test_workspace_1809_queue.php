<?php
// Offline, deterministic, PHP 8.1-compatible projection tests. No Moodle DB/ACL runtime.
define('MOODLE_INTERNAL', true);
class moodle_url {
    private string $path;
    private array $params;
    public function __construct(string $path, array $params) { $this->path = $path; $this->params = $params; }
    public function out(bool $escaped = true): string {
        return $this->path . '?' . http_build_query($this->params);
    }
}
require_once(__DIR__ . '/../classes/local/battle_plan_review_cycle_service.php');
require_once(__DIR__ . '/../classes/local/bms_workspace_action_service.php');
use local_kbacompany\local\bms_workspace_action_service as Q;
use local_kbacompany\local\battle_plan_review_cycle_service as History;
$count = 0;
function ok1809(bool $pass, string $name): void {
    global $count;
    if (!$pass) { throw new RuntimeException('FAIL ' . $name); }
    $count++;
}
$company = 'company-alpha'; $actor = 42; $today = '2026-10-25';
$task = static function(string $uuid, string $status, string $due, int $assignee = 42): array {
    return ['uuid'=>$uuid, 'title'=>'Задача ' . $uuid, 'provider'=>'bms', 'status'=>$status,
        'due_date'=>$due, 'assignee_id'=>$assignee, 'effective_assignee_id'=>$assignee,
        'reviewer_id'=>42];
};
$rows = Q::project($company,$actor,[$task('a','doing','2026-10-24'),
    $task('b','doing','2026-10-25'),$task('c','returned',''),
    $task('done','done','2026-10-23'),$task('future','doing','2026-11-01'),
    $task('other','doing','2026-10-22',99),
    ['uuid'=>'remote','provider'=>'external','status'=>'doing','title'=>'remote','due_date'=>'2026-10-20',
        'effective_assignee_id'=>42]], [], [], [], $today);
ok1809(count($rows) === 3,'personal due/returned only');
ok1809($rows[0]['id'] === 'task:c','returned takes precedence');
ok1809($rows[1]['id'] === 'task:a','overdue next');
ok1809($rows[2]['id'] === 'task:b','due today third');
ok1809(str_contains($rows[1]['url'],'company-alpha') && str_contains($rows[1]['url'],'task=a'), 'task canonical URL');
ok1809(strpos(implode(' ',array_column($rows,'id')), 'other') === false,'other executor filtered');
$review = $task('a','review','2026-10-24');
$review['reviewer_id'] = $actor;
$rows = Q::project($company,$actor,[$task('a','doing','2026-10-22')],[$review],[],[],$today);
ok1809(count($rows) === 1,'same task one row');
ok1809($rows[0]['action'] === 'bmsaction_review_task','review overrides overdue');
$review['reviewer_id'] = 999;
ok1809(Q::project($company,$actor,[],[$review],[],[],$today) === [],'unassigned review filtered');
$returned = History::append_event('', 'submitted', 42, 1000);
$returned = History::append_event($returned, 'returned', 9, 1010);
$submitted = History::append_event($returned, 'submitted', 42, 1020);
$plans = [
    (object)['uuid'=>'p1','userid'=>42,'reviewerid'=>9,'status'=>'draft','periodstart'=>'2026-10-19','statepayload'=>$returned],
    (object)['uuid'=>'p2','userid'=>42,'reviewerid'=>9,'status'=>'draft','periodstart'=>'2026-10-19','statepayload'=>''],
    (object)['uuid'=>'p3','userid'=>8,'reviewerid'=>42,'status'=>'submitted','periodstart'=>'2026-10-19',
        'firstname'=>'Alex','lastname'=>'Stone','statepayload'=>$submitted],
    (object)['uuid'=>'p4','userid'=>8,'reviewerid'=>7,'status'=>'submitted','periodstart'=>'2026-10-19','statepayload'=>$submitted],
    (object)['uuid'=>'p5','userid'=>42,'reviewerid'=>9,'status'=>'draft','periodstart'=>'2026-10-19','statepayload'=>$submitted],
];
$rows = Q::project($company,$actor,[],[],$plans,[],$today);
ok1809(count($rows) === 2,'returned and assigned submitted only');
ok1809($rows[0]['id'] === 'plan:p1','returned own plan first');
ok1809($rows[1]['id'] === 'plan:p3','review own assigned plan');
ok1809(str_contains($rows[1]['meta'],'Alex Stone'),'reviewed owner distinguishes multiple plans');
ok1809(str_contains($rows[0]['url'],'plan=p1'),'plan deep link');
$indicator = static function(string $id,bool $needs,string $severity): array {
    return ['binding'=>['uuid'=>$id], 'metric'=>['uuid'=>'m'.$id,'code'=>'001', 'name'=>'Выручка'],
        'context_label'=>'Отдел продаж', 'severity'=>$severity,
        'management_cycle'=>['requires_new_decision'=>$needs]];
};
$rows = Q::project($company,$actor,[],[],[],[$indicator('one',true,'critical'),
    $indicator('two',false,'critical'),$indicator('three',true,'medium'),$indicator('one',true,'critical')],$today);
ok1809(count($rows)===2,'non-actionable and repeated exception filtered');
ok1809($rows[0]['id']==='indicator:one','critical decision first');
ok1809(str_contains($rows[0]['url'],'stat=mone'),'KPI points to metric');
ok1809(str_contains($rows[0]['url'],'uuid=company-alpha'),'KPI company is explicit');
$rows = Q::project($company,$actor,[$task('a','doing','2026-10-22'),$task('b','doing','2026-10-22')],[],[],[],$today,1);
ok1809(count($rows)===1,'maximum list limit');
ok1809(Q::project($company,$actor,[$task('a','doing','2026-10-22')],[],[],[],$today,0)===[],'zero max');
// The pure projection never modifies data and never inserts a generic manager alert.
ok1809(Q::project($company,$actor,[],[],[],[],$today)===[],'empty explicit state');
echo "BMS1809: {$count} projection checks OK\n";
