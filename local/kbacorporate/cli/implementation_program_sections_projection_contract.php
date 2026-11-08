<?php
// Standalone fixture-based contract check: no Moodle DB used here.
define('MOODLE_INTERNAL', true);
require_once dirname(__DIR__) . '/classes/local/implementation_program_sections_projection.php';
use local_kbacorporate\local\implementation_program_sections_projection as stages;
$tests = 0;
$check = static function(bool $condition, string $label) use (&$tests): void {
    $tests++;
    if (!$condition) { throw new RuntimeException('FAIL: ' . $label); }
};
$section = (object)[
    'code' => 'A1', 'name' => 'Objectives', 'status' => 'risk',
    'totalcriteria' => 5, 'gapcriteria' => 3, 'coveredcriteria' => 2,
    'tasktotal' => 4, 'completedtasks' => 1, 'reviewtasks' => 1,
    'overduetasks' => 2, 'unassignedtasks' => 0, 'acceptedevidence' => 1,
    'criteria' => [(object)['criterionname' => 'private details']],
    'internal_user' => 'private@example.test',
];
$blank = clone $section;
$blank->code = 'A2'; $blank->name = 'Processes';
$blank->tasktotal = 0; $blank->completedtasks = 0;
$model = (object)['sections' => [$section, $blank],
    'summary' => (object)['tasktotal' => 4, 'completedtasks' => 1, 'overduetasks' => 2, 'acceptedevidence' => 1],
    'raw_admin_notes' => 'secret'];
$program = ['company_id' => 81, 'program_id' => 52, 'audit_id' => 99, 'curator_email' => 'secret@example.test'];
$result = stages::sanitize_model($model, $program);
$check($result['company_id'] === 81, 'company identity retained');
$check($result['program_id'] === 52, 'program identity retained');
$check($result['audit_id'] === 99, 'audit identity retained');
$check($result['available'] === true, 'confirmed model available');
$check(count($result['sections']) === 2, 'two sections');
$check($result['sections'][0]['code'] === 'A1', 'section codes retained');
$check($result['sections'][0]['status'] === 'risk', 'source status preserved');
$check($result['sections'][0]['completed_count'] === 1, 'completed tasks count');
$check($result['sections'][0]['review_count'] === 1, 'review tasks count');
$check($result['sections'][0]['overdue_count'] === 2, 'overdue tasks count');
$check($result['sections'][0]['accepted_evidence_count'] === 1, 'accepted evidence count');
$check($result['sections'][0]['task_completion_percent'] === 25.0, 'task completion math');
$check($result['sections'][1]['task_completion_percent'] === null, 'no false zero for no tasks');
$check($result['summary']['section_count'] === 2, 'section count');
$check(!isset($result['sections'][0]['criteria']), 'no raw criteria');
$check(!isset($result['sections'][0]['internal_user']), 'no member identifiers');
$check(!isset($result['curator_email']), 'no curator data');
$check(!isset($result['raw_admin_notes']), 'no raw notes');
$check(count($result['sections'][0]) === 13, 'strict section allowlist');
echo 'PASS implementation program sections projection: ' . $tests . " cases\n";
