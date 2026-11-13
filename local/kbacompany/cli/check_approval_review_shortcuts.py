#!/usr/bin/env python3
"""Regress the quick reviewer workflow without requiring Moodle bootstrap."""
from pathlib import Path
root = Path(__file__).resolve().parents[1]
overview = (root / 'battle_plan_overview.php').read_text(encoding='utf-8')
plan = (root / 'battle_plan.php').read_text(encoding='utf-8')
ui = (root / 'classes/local/battle_plan_ui_service.php').read_text(encoding='utf-8')
for token in ["'fromqueue'=>1", "'qweek'", "'qorgnode'", "'qreviewer'", "'qattention'", "'qfilter'", "'qpage'", "'qteamsignal'", "'qemployeeinsight'", "latest_return_note", "html_writer::tag('details'", "html_writer::link($reviewurl"]:
    assert token in overview, token
for token in ["optional_param('fromqueue', 0, PARAM_BOOL)", "new moodle_url('/local/kbacompany/battle_plan_overview.php', $backparams)", "set_anchor('bms24-approval-queue')", "($action === 'reviewplan' && $queueurl !== null)", 'ui::review($uuid,$data,$values,$errors,$queuefields)']:
    assert token in plan, token
# 1801 moved queue return into the shared plan navigation, not into review tab only.
assert "ui::record_navigation($uuid, $data, $view, $tab, $queuefields, $queueurl)" in plan
assert "\\html_writer::link($queueurl" in ui
assert "array_merge(['uuid'=>$company,'plan'=>$p['uuid'],'action'=>'reviewplan'" in ui
assert '$queuefields));' in ui
assert "battle_plan_service::review($uuid" in plan
assert "require_sesskey();" in plan
assert 'battle_plan_service::review(' not in overview
assert "optional_param('returnurl'" not in plan, 'no attacker-controlled redirect'
print('approval review shortcut/return-contract PASS')
