<?php
// Administrator-only diagnostics for the weekly-coordination HTTP 500 incident.
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\battle_plan_management_overview_service;
use local_kbacompany\local\battle_plan_week_readiness_service;
use local_kbacompany\local\battle_plan_coverage_service;
use local_kbacompany\local\battle_plan_week_coordination_service;

require_login();
$systemcontext = context_system::instance();
require_capability('moodle/site:config', $systemcontext);
$uuid = required_param('uuid', PARAM_ALPHANUMEXT);
$week = optional_param('week', '', PARAM_TEXT);
$orgnode = optional_param('orgnode', '', PARAM_ALPHANUMEXT);
$step = optional_param('step', 'overview', PARAM_ALPHA);
$allowed = ['overview', 'readiness', 'coverage', 'coordination'];
if (!in_array($step, $allowed, true)) {
    $step = 'overview';
}
$PAGE->set_url(new moodle_url('/local/kbacompany/battle_plan_week_coordination_diag.php',
    ['uuid' => $uuid, 'week' => $week, 'orgnode' => $orgnode]));
$PAGE->set_context($systemcontext);
$PAGE->set_title('BMS24: диагностика координации');
$PAGE->set_heading('BMS24: диагностика координации');
echo $OUTPUT->header();
echo $OUTPUT->heading('Диагностика управленческой координации');
echo html_writer::tag('p', 'Доступно только администратору Moodle. Выберите проверку; данные не изменяются.');
foreach ($allowed as $candidate) {
    echo html_writer::link(new moodle_url('/local/kbacompany/battle_plan_week_coordination_diag.php',
        ['uuid' => $uuid, 'week' => $week, 'orgnode' => $orgnode, 'step' => $candidate]),
        s($candidate), ['class' => 'btn btn-secondary m-1']);
}
$started = microtime(true);
try {
    switch ($step) {
        case 'overview':
            $result = battle_plan_management_overview_service::context($uuid, (int)$USER->id, $week,
                $orgnode, 0, 'all');
            break;
        case 'readiness':
            $result = battle_plan_week_readiness_service::context($uuid, (int)$USER->id, $week,
                $orgnode, 'all');
            break;
        case 'coverage':
            $result = battle_plan_coverage_service::context($uuid, (int)$USER->id, $week,
                $orgnode, 'all');
            break;
        default:
            $result = battle_plan_week_coordination_service::context($uuid, (int)$USER->id, $week,
                $orgnode, 0, 4);
            break;
    }
    echo $OUTPUT->notification('PASS: ' . s($step) . ' (' . round(microtime(true) - $started, 2) . ' с)',
        'success');
    echo html_writer::tag('p', 'Данные сформированы. Разделов ответа: ' . count((array)$result));
} catch (\Throwable $e) {
    $reference = 'BPWC-' . date('YmdHis') . '-' . substr(hash('sha256', $e->getFile() . ':' .
        $e->getLine() . ':' . $e->getMessage()), 0, 8);
    error_log('[BMS24 ' . $reference . '] coordination diagnostic: ' . (string)$e);
    echo $OUTPUT->notification('FAIL: ' . s($step) . ' — ' . s(get_class($e)) .
        ' (' . s($reference) . ')', 'error');
    // Only administrators with moodle/site:config can access this page.
    echo html_writer::tag('pre', s($e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine()));
}
echo $OUTPUT->footer();
