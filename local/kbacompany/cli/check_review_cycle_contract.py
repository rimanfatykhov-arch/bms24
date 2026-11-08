#!/usr/bin/env python3
"""Check that all approval routes append a durable event before update_record."""
from pathlib import Path
root=Path(__file__).resolve().parents[1]
service=(root/'classes/local/battle_plan_service.php').read_text()
projection=(root/'classes/local/battle_plan_ui_service.php').read_text()
assert "battle_plan_review_cycle_service::append_event" in service
transition=service.split('public static function transition(',1)[1].split('public static function review(',1)[0]
review=service.split('public static function review(',1)[1].split('public static function outstanding_review_corrections(',1)[0]
assert "['submit', 'approve', 'selfapprove']" in transition
assert "'submitted'" in transition and "'selfapproved'" in transition
assert "'returned'" in review and "'approved'" in review
assert transition.index('append_event') < transition.index("$DB->update_record('local_kbacompany_bplan', $plan)")
assert review.index('append_event') < review.index("$DB->update_record('local_kbacompany_bplan', $plan)")
assert "review_cycle_history" in projection
print('review cycle contract PASS')
