#!/usr/bin/env python3
"""Guard access filtering and read-only presentation of approval timings."""
from pathlib import Path
root=Path(__file__).resolve().parents[1]
service=(root/'classes/local/battle_plan_review_timeliness_service.php').read_text()
page=(root/'battle_plan_overview.php').read_text()
upgrade=(root/'db/upgrade.php').read_text()
for token in ['company_context_service::context', 'task_service::can_manage',
 '(int)$plan->userid !== $actor', '(int)$plan->reviewerid !== $actor',
 '(int)$plan->observerid !== $actor', "'companyuuid'=>$companyuuid", "'periodstart'=>$week",
 '$reviewerfilter > 0', "$orgnodefilter !== ''", "'submitted'", "'active'", "'closed'",
 'self::ATTENTION_SECONDS', 'approved_at', 'submitted_at', 'selfapproved',
 'array_slice($waiting, 0, 20)', 'array_slice($recentapproved, 0, 10)']:
 assert token in service, token
assert 'battle_plan_review_timeliness_service::context(' in page
assert "'bms24-approval-timing'" in page
assert "if ($oldversion < 2026101796)" in upgrade
assert 'battle_plan_service::review(' not in page[page.index('// Review-time report'):page.index('// Prior-week read-only')]
print('review timeliness contract PASS')
