<?php
/** Standalone pure-projection regression (no Moodle bootstrap/database). */
namespace {
    define('MOODLE_INTERNAL', true);
}
namespace local_kbacompany\local {
    // The actual classify() implementation depends only on the canonical STATES constant.
    class battle_plan_service {
        public const STATES = ['draft', 'submitted', 'active', 'closed'];
    }
    require __DIR__ . '/../classes/local/battle_plan_week_readiness_service.php';
    require __DIR__ . '/../classes/local/battle_plan_ui_service.php';

    $data = [
        'selected' => ['status'=>'submitted', 'user_id'=>21, 'reviewer_id'=>22],
        'employees' => [22 => ['name'=>'Reviewer']],
        'items' => [
            ['task'=>['product'=>'Signed contract', 'assignee_id'=>21, 'due_date'=>'2026-10-30']],
            ['task'=>['product'=>'', 'assignee_id'=>0, 'due_date'=>'']],
        ],
        'position_indicators' => [['uuid'=>'m1'],['uuid'=>'m2']],
        'metric_status_counts' => ['ok'=>1,'nodata'=>1],
    ];
    $r=battle_plan_ui_service::review_checklist_projection($data);
    $expected=['readiness'=>'ready_activate','taskcount'=>2,'missingproducts'=>1,
        'unassigned'=>1,'noduedate'=>1,'metrics'=>2,'missingmetricfacts'=>1,'reviewerok'=>true];
    foreach ($expected as $key=>$value) {
        if (!array_key_exists($key,$r) || $r[$key]!==$value) {
            throw new \RuntimeException('Mismatch '.$key.': '.var_export($r[$key]??null,true));
        }
    }
    $data['selected']['reviewer_id']=0;
    $missing=battle_plan_ui_service::review_checklist_projection($data);
    if ($missing['reviewerok']!==false || $missing['readiness']!=='blocked' ||
            $missing['blockers']!==['missing_reviewer']) {
        throw new \RuntimeException('Missing reviewer not flagged');
    }
    $data['selected']['reviewer_id']=22;
    $data['items']=[];
    $empty=battle_plan_ui_service::review_checklist_projection($data);
    // A submitted plan stays governed by the actual lifecycle, which enforces task count on submission.
    // The checklist displays zero tasks but must not silently create a *new* approval blocker.
    if ($empty['taskcount']!==0 || $empty['readiness']!=='ready_activate') {
        throw new \RuntimeException('Unexpected approval blocker created for submitted plan');
    }
    echo "review readiness projection PASS\n";
}
