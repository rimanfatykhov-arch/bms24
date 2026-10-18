# BBB undefined apply_group_log_filter

Symptom:
`Call to undefined method local_kbacorporate\\local\\bigbluebutton::apply_group_log_filter()`

Fixed in v0.14.2.1 by adding a schema-aware helper. It applies `groupid` to BBB log SQL only when that field exists in the live `bigbluebuttonbn_logs` table.
