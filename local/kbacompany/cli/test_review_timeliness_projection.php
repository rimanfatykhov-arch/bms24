<?php
// Pure review-cycle calculation; no Moodle bootstrap or database needed.
define('MOODLE_INTERNAL', true);
require_once(__DIR__ . '/../classes/local/battle_plan_review_timeliness_service.php');
use local_kbacompany\local\battle_plan_review_timeliness_service as Timeliness;
function verify($condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
function r(string $uuid, string $status, int $sent, int $approved, int $approver,
        int $employee = 10, string $unit = 'u1', int $reviewer = 11): array {
    return ['plan_uuid'=>$uuid, 'status'=>$status, 'unit_uuid'=>$unit, 'unit_name'=>'Unit ' . $unit,
        'reviewer_id'=>$reviewer, 'reviewer_name'=>'Reviewer ' . $reviewer,
        'employee_id'=>$employee, 'employee_name'=>'Employee ' . $employee,
        'approved_by'=>$approver, 'approved_by_name'=>'Approver ' . $approver,
        'submitted_at'=>$sent, 'approved_at'=>$approved];
}
$now = 1000000;
$plans = [
    r('waiting-new', 'submitted', $now-3600, 0, 0),
    r('waiting-long', 'submitted', $now-48*3600, 0, 0, 10, 'u2', 12),
    r('waiting-unknown', 'submitted', 0, 0, 0),
    r('waiting-future', 'submitted', $now+3600, 0, 0),
    r('approved', 'active', $now-7200, $now-3600, 11),
    r('closed', 'closed', $now-9000, $now-5400, 11, 10, 'u2', 12),
    r('self', 'active', $now-1200, $now-1200, 10),
    r('approved-bad', 'closed', $now-3600, $now-5000, 11),
    r('returned', 'draft', 0, 0, 0),
];
$actual = Timeliness::summarize($plans, $now);
verify($actual['counts']['waiting'] === 4, 'pending count');
verify($actual['counts']['waiting_long'] === 1, '48h inclusive threshold');
verify($actual['counts']['waiting_unknown'] === 2, 'unknown/future submissions');
verify($actual['counts']['approved_known'] === 2, 'approved timestamps');
verify($actual['counts']['approved_unknown'] === 1, 'bad approved timeline excluded');
verify($actual['counts']['selfapproved'] === 1, 'self approval separate');
verify($actual['avg_seconds'] === 3600, 'correct average, excludes self and missing');
verify($actual['waiting_total'] === 4, 'total before display cap');
verify($actual['waiting'][0]['plan_uuid'] === 'waiting-long', 'oldest first');
verify($actual['waiting'][3]['elapsed_seconds'] === null, 'future submission not negative');
verify(count($actual['units']) === 2 && count($actual['reviewers']) === 2, 'group count');
verify(array_sum(array_column($actual['units'], 'waiting')) === 4, 'group sum pending');
verify(array_sum(array_column($actual['units'], 'approved_known')) === 2, 'group sum approved');
verify(Timeliness::summarize([], $now)['avg_seconds'] === null, 'empty means no data');
$many = [];
for ($i=1; $i<=35; $i++) { $many[] = r('p'.$i, 'submitted', $now-$i*60, 0, 0); }
$slice = Timeliness::summarize($many, $now);
verify($slice['waiting_total'] === 35 && count($slice['waiting']) === 20, 'display cap is after aggregate');
verify($slice['counts']['waiting'] === 35, 'no aggregate pagination');
echo "review timeliness projection PASS\n";
