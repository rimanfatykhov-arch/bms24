<?php
/** Pure release test for the authorised Battle Plan navigation projection. */
define('MOODLE_INTERNAL', true);
require_once(__DIR__ . '/../classes/local/battle_plan_navigation_service.php');
use local_kbacompany\local\battle_plan_navigation_service as nav;

function check(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
function sample(string $status='draft', bool $edit=false, bool $manager=false): array {
    return ['selected'=>['uuid'=>'plan-A', 'period_start'=>'2026-10-19', 'status'=>$status,
        'reviewer_id'=>8, 'user_id'=>7], 'selected_can_edit'=>$edit, 'can_manage'=>$manager,
        'actor'=>7];
}
$cases=0;
foreach (['draft','submitted','active','closed'] as $status) {
    foreach ([false,true] as $canedit) {
        foreach ([false,true] as $manager) {
            foreach (['detail','edit','fill','action','metrics'] as $view) {
                $m=nav::project(sample($status,$canedit,$manager),$view,'history');
                check(count($m['tabs'])===5,'all five read-only tabs remain discoverable');
                check($m['week']==='2026-10-19' && $m['plan']==='plan-A','week and authorised plan stable');
                $modes=array_column($m['modes'],'key');
                check(in_array('edit',$modes,true)===($canedit && $status!=='closed'),'edit ACL boundary');
                check(in_array('fill',$modes,true)===($canedit && in_array($status,['draft','active'],true)), 'fill state/ACL boundary');
                check($m['canmanage']===$manager,'manager-only shortcut');
                if ($view==='metrics') {
                    check($m['active']==='indicators','metrics specialist tab');
                } else if ($view==='detail') {
                    check($m['active']==='history','current card tab');
                } else {
                    check($m['active']==='','editing does not pretend a card tab is active');
                }
                ++$cases;
            }
        }
    }
}
check(array_sum(array_map(static function(array $tab): int {return (int)$tab['selected'];},
    nav::project(sample(),'detail','history')['tabs']))===1, 'one aria-current card tab');
check(array_sum(array_map(static function(array $tab): int {return (int)$tab['selected'];},
    nav::project(sample(),'edit','history')['tabs']))===0, 'no fake current tab on edit form');
echo "battle plan navigation projection PASS: {$cases} state/role/view combinations + tab invariants\n";
