#!/usr/bin/env python3
"""Approval checklist must remain advisory and reuse the canonical readiness contract."""
from pathlib import Path
root=Path(__file__).resolve().parents[1]
ui=(root/'classes/local/battle_plan_ui_service.php').read_text()
backend=(root/'classes/local/battle_plan_service.php').read_text()
for token in ['review_checklist_projection(array $data)',
              'battle_plan_week_readiness_service::classify(',
              'review_readiness_panel($company,$data)',
              "['plan'=>$plan['uuid'],'tab'=>'tasks']",
              "['plan'=>$plan['uuid'],'tab'=>'indicators']"]:
    assert token in ui, token
for token in ["'missingproducts'", "'unassigned'", "'noduedate'", "'missingmetricfacts'"]:
    assert token in ui, token
assert 'private static function review_readiness_panel' in ui
panel=ui.split('private static function review_readiness_panel',1)[1].split('public static function review',1)[0]
assert 'battle_plan_service::transition' not in panel
assert 'throw new' not in panel
assert "['method'=>'post'" not in panel
assert "'decision'=>'approve'" not in panel
assert 'function review(' in backend or 'function review(' in ui
print('approval checklist read-only source contract PASS')
