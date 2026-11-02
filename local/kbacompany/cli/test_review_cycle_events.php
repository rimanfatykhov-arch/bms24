<?php
// Tests for durable review events without Moodle bootstrap.
define('MOODLE_INTERNAL', true);
require_once(__DIR__ . '/../classes/local/battle_plan_review_cycle_service.php');
use local_kbacompany\local\battle_plan_review_cycle_service as EventLog;
function assertok($ok, $message) { if (!$ok) { throw new \RuntimeException($message); } }
$payload = json_encode(['week2'=>['goal'=>'Example'], 'review_correction_ack'=>['keep'=>true]]);
$payload = EventLog::append_event($payload, 'submitted', 11, 1000);
$payload = EventLog::append_event($payload, 'returned', 12, 4600);
$payload = EventLog::append_event($payload, 'submitted', 11, 5000);
$payload = EventLog::append_event($payload, 'approved', 12, 6800);
$result = EventLog::project($payload, [11=>'Employee',12=>'Reviewer']);
assertok($result['cycles']===2 && $result['timed']===2, 'two measured approval cycles');
assertok($result['rows'][1]['duration_seconds']===3600, 'returned cycle duration');
assertok($result['rows'][3]['duration_seconds']===1800, 'approved cycle duration');
assertok($result['average_seconds']===2700, 'average includes both decisions');
assertok($result['rows'][3]['actor_name']==='Reviewer', 'names resolved');
$decoded = json_decode($payload, true);
assertok($decoded['week2']['goal']==='Example' && $decoded['review_correction_ack']['keep'], 'other payload retained');
$self = EventLog::append_event('', 'selfapproved', 11, 1234);
assertok(EventLog::project($self)['timed']===0, 'selfapproval not included in mean');
assertok(EventLog::project('{"week2":{}}')['cycles']===0, 'legacy has no synthetic events');
$legacy = EventLog::append_event('{"week2":{}}', 'approved', 12, 4600);
assertok(EventLog::project($legacy)['timed']===0, 'no invented historic submission');
$failure=false;
try { EventLog::append_event('NOT JSON','submitted',11,1000); } catch (\UnexpectedValueException $e) { $failure=true; }
assertok($failure, 'corrupt payload rejected without loss');
$failure=false;
try { EventLog::append_event('', 'bogus',11,1000); } catch (\InvalidArgumentException $e) { $failure=true; }
assertok($failure, 'unsupported event rejected');
echo "review cycle event test PASS\n";
