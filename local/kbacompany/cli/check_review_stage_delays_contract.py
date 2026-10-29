#!/usr/bin/env python3
"""Build 1799 read-only access, UX and pagination integration contract."""
from pathlib import Path
r=Path(__file__).resolve().parents[1]
timing=(r/'classes/local/battle_plan_review_timeliness_service.php').read_text()
overview=(r/'battle_plan_overview.php').read_text()
stages=(r/'classes/local/battle_plan_review_stage_delay_service.php').read_text()
assert 'company_context_service::context($companyuuid, $actor)' in timing
assert "['companyuuid'=>$companyuuid, 'periodstart'=>$week]" in timing
for access in [
    'if (!$canmanage && (int)$plan->userid !== $actor',
    'if ($reviewerfilter > 0 && (int)$plan->reviewerid !== $reviewerfilter)',
    "if ($orgnodefilter !== '' && $unit !== $orgnodefilter)",
]:
    assert timing.index(access)<timing.index('$cycleplans[]'), access
assert "'status'=>(string)$plan->status" in timing
assert "'stage_delays'=>battle_plan_review_stage_delay_service::summarize($cycleplans, time())" in timing
assert '$approvaltiming[\'stage_delays\']' in overview
assert overview.index('$stagedelays = ') > overview.index('$approvaltiming = ')
assert overview.index('$stageplanrows = ') < overview.index('$delayrows = array_slice')
assert "['uuid'=>$uuid, 'plan'=>$pr['plan_uuid']" in overview
assert "['tab'=>'history']" in overview
assert "['tab'=>'overview']" in overview
assert 'bms24-stage-delay' in overview
assert "'manager_cycles'" in stages and "'employee_cycles'" in stages
assert "'review_cycle_events'" in stages
assert 'get_records(' not in stages and 'update_record(' not in stages
assert 'if ($stage === \'manager\' && $status === \'submitted\')' in stages
assert 'else if ($stage === \'employee\' && $status === \'draft\')' in stages
assert "['stage'] . '_open'" in stages
assert 'array_sum(array_column($projection' in stages
assert 'array_slice($stagedelays' not in timing
assert (r/'db/upgrade.php').read_text().count('if ($oldversion < 2026101799)')==1
print('review stage delay ACL, workflow and pagination contract PASS')
