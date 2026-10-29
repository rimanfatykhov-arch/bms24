<?php
// Pure PHP8.1-compatible projection test. Does not test live Moodle authentication.
define('MOODLE_INTERNAL', true);
require_once(__DIR__ . '/../classes/local/bms_workspace_widget_service.php');
require_once(__DIR__ . '/../classes/local/bms_workspace_load_policy.php');
require_once(__DIR__ . '/../classes/local/bms_workspace_action_service.php');
require_once(__DIR__ . '/../classes/local/bms_workspace_focus_service.php');
use local_kbacompany\local\bms_workspace_focus_service as Focus;
use local_kbacompany\local\bms_workspace_widget_service as Widgets;
use local_kbacompany\local\bms_workspace_load_policy as Policy;

$count = 0;
function assert1810(bool $ok, string $name): void {
    global $count;
    if (!$ok) { throw new RuntimeException('FAIL ' . $name); }
    $count++;
}
$employee = Focus::modes(false, false);
$manager = Focus::modes(true, false);
$owner = Focus::modes(true, true);
assert1810($employee === ['all','personal'], 'employee no manager or owner scopes');
assert1810($manager === ['all','personal','management'], 'manager scopes');
assert1810($owner === ['all','personal','management','owner'], 'owner scopes');
assert1810(Focus::select('owner',false,false) === 'all','forbidden owner fallback');
assert1810(Focus::select('management',false,false) === 'all','forbidden manager fallback');
assert1810(Focus::select('owner',true,false) === 'all','manager not owner');
assert1810(Focus::select('management',true,false) === 'management','manager permitted');
assert1810(Focus::select('owner',true,true) === 'owner','owner permitted');
assert1810(Focus::select('javascript:',true,true) === 'all','unknown value fallback');
assert1810(Focus::select('ALL',true,true) === 'all','case strict fallback');
$visible = Widgets::DEFAULT_ORDER;
assert1810(Focus::visible($visible,'all') === $visible,'all preserves stored order');
assert1810(Focus::visible($visible,'personal') === ['today','plan','actions','overview','attention','tasks'], 'personal subset');
assert1810(Focus::visible($visible,'management') === ['attention','management'], 'manager subset');
assert1810(Focus::visible($visible,'owner') === ['attention','management','architecture','company'], 'owner subset');
assert1810(Focus::visible(['company','today','attention'], 'owner') === ['company','attention'], 'preserves user order');
assert1810(Focus::visible(['today','tasks'],'management') === [],'not override user hidden widgets');
$employeevisible = Widgets::available(false);
assert1810(!in_array('management',$employeevisible,true),'employee widget acl intact');
assert1810(Focus::visible($employeevisible,'all') === $employeevisible,'employee all excludes management');
assert1810(!Policy::requirements(Focus::visible($visible,'personal'),false)['management'], 'personal scope skips manager model');
assert1810(Policy::requirements(Focus::visible($visible,'management'),true)['management'],'manager model loads when displayed');
assert1810(Policy::requirements(Focus::visible($visible,'owner'),true)['organization'],'owner architecture loads on demand');
assert1810(!Policy::requirements([],false)['work'],'empty view avoids work model');
assert1810(Policy::requirements(null)['legacy'],'legacy model compatibility');
$items = [
 ['action'=>'bmsaction_decide_indicator', 'id'=>'kpi:1'],
 ['action'=>'bmsaction_review_plan', 'id'=>'plan:1'],
 ['action'=>'bmsaction_fix_task', 'id'=>'task:1'],
 ['action'=>'bmsaction_open_overdue', 'id'=>'task:2'],
 ['action'=>'bmsaction_review_task', 'id'=>'task:3'],
 ['action'=>'bmsaction_fix_plan', 'id'=>'plan:2'],
 ['action'=>'bmsaction_open_today', 'id'=>'task:4'],
 ['action'=>'unexpected_action', 'id'=>'unknown:1'],
];
assert1810(count(Focus::actions($items,'all')) === 8, 'legacy queue preserved');
assert1810(array_column(Focus::actions($items,'personal'),'id') === ['task:1','task:2','plan:2','task:4'], 'personal only obligations');
assert1810(array_column(Focus::actions($items,'management'),'id') === ['kpi:1','plan:1','task:3'], 'manager decisions only');
assert1810(array_column(Focus::actions($items,'owner'),'id') === ['kpi:1','plan:1','task:3'], 'owner manager decisions (no private leak)');
assert1810(Focus::actions([], 'management') === [],'empty queue handled');
assert1810(count(Focus::actions(array_fill(0,20,$items[0]),'all')) === 8,'max 8 visible actions');
echo "BMS1810: {$count} role, load policy and action-scope checks passed\n";
