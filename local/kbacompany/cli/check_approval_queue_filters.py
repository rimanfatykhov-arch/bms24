#!/usr/bin/env python3
"""Source regression gate for week-approval queue filters and department report."""
from pathlib import Path
root = Path(__file__).resolve().parents[1]
ui = (root / 'battle_plan_overview.php').read_text(encoding='utf-8')
service = (root / 'classes/local/battle_plan_approval_queue_service.php').read_text(encoding='utf-8')
management = (root / 'classes/local/battle_plan_management_overview_service.php').read_text(encoding='utf-8')
assert "optional_param('approvalfilter', 'all', PARAM_ALPHA)" in ui
assert 'battle_plan_approval_queue_service::summarize' in ui
assert ui.index('::summarize(') < ui.index('array_slice($queueprojection[\'rows\']'), 'filter before page'
assert 'approvalfilter' in ui[ui.index('$linkparams = $approvalparams'):ui.index("'bms24-approval-pagination'")]
assert "'orgnode'=>$unit['uuid']" in ui, 'unit drilldown is ACL-filtered at context()'
assert "'reviewer'=>$data['reviewer_filter']" in ui
assert '$reviewqueue[] = $row;' in management
assert management.index('$reviewqueue[] = $row;') < management.index("if ($attention !== 'all' && $row['attention'] !== $attention)"), 'attention independent'
assert 'may_approve_department_plan' in management
assert 'public static function summarize' in service
assert "['all', 'ready', 'review']" in service
assert 'battle_plan_service::review' not in ui
print('approval queue quick-filter contract PASS')
