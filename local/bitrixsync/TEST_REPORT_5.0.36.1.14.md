# TEST REPORT — v5.0.36.1.14

Static:
- all PHP syntax checked;
- multi_contact_deal_service signature verified: process(int $dealid, bool $dryrun=true);
- ZIP integrity/root verified.

Production:
1. Trigger Deal 4023 again or update its allowed stage.
2. Run cron/queue.
3. Open Deal Contact Diagnostics.
4. Last automatic Multi-Contact Deal Run should show both 12533 and 12681.
5. Contact 12681 should become CREATED with Moodle User ID > 0.
6. Enrolment, Corporate sync, access email and Timeline are then verified separately.
