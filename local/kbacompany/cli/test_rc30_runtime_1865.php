<?php
/**
 * Isolated RC30 runtime classifier regression. Run from plugin directory:
 * php cli/test_rc30_runtime_1865.php
 */
define('MOODLE_INTERNAL', true);
require_once(__DIR__.'/../classes/local/task_rc30_acceptance_service.php');

use local_kbacompany\local\task_rc30_acceptance_service as preflight;

$cases = [
    ['php'=>'8.1.34', 'db'=>'mysql', 'server'=>['version'=>'5.7.21', 'description'=>'MySQL'], 'warnings'=>[]],
    ['php'=>'8.1.40', 'db'=>'mysql', 'server'=>['version'=>'5.7.44-log', 'description'=>'MySQL'], 'warnings'=>[]],
    ['php'=>'8.4.24', 'db'=>'mysql', 'server'=>['version'=>'5.7.21'], 'warnings'=>['php81_target_not_verified']],
    ['php'=>'8.1.34', 'db'=>'mysql', 'server'=>['version'=>'8.0.41'], 'warnings'=>['mysql57_target_not_verified']],
    ['php'=>'8.1.34', 'db'=>'mysql', 'server'=>['version'=>'5.5.5-10.11.8-MariaDB'], 'warnings'=>['mysql57_target_not_verified']],
    ['php'=>'8.1.34', 'db'=>'mariadb', 'server'=>['version'=>'10.11.8'], 'warnings'=>['mysql57_target_not_verified']],
    ['php'=>'8.1.34', 'db'=>'mysql', 'server'=>[], 'warnings'=>['mysql57_target_not_verified']],
    ['php'=>'7.4.33', 'db'=>'postgres', 'server'=>['version'=>'13.2'], 'warnings'=>['php81_target_not_verified', 'mysql57_target_not_verified']],
];
foreach ($cases as $index => $case) {
    $result = preflight::runtime_status($case['php'], $case['db'], $case['server']);
    if ($result['warnings'] !== $case['warnings']) {
        fwrite(STDERR, 'FAIL runtime scenario '.($index+1).': '.json_encode($result)."\n");
        exit(1);
    }
}
echo 'PASS: '.count($cases)." RC30 runtime scenarios; no Moodle or database calls\n";
