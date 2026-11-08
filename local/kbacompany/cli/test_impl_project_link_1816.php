<?php
/** Pure tests that can run without a Moodle DB (full ACL tests require Moodle PHPUnit). */
define('MOODLE_INTERNAL', true);
require dirname(__DIR__) . '/classes/local/implementation_project_link_service.php';
use local_kbacompany\local\implementation_project_link_service as S;
function verify($cond, $label) {
    if (!$cond) { fwrite(STDERR, "FAIL $label\n"); exit(1); }
    echo "PASS $label\n";
}
$c = S::contract();
verify($c['read_only_corporate'] && in_array('no_task_copy', $c['rules'], true), 'no task copy');
verify(in_array('one_project_one_program', $c['rules'], true), 'project uniqueness');
$items = [
    ['uuid' => 'a', 'kind' => 'project', 'status' => 'published', 'stale' => false, 'name' => 'A'],
    ['uuid' => 'b', 'kind' => 'project', 'status' => 'draft', 'stale' => false, 'name' => 'B'],
    ['uuid' => 'c', 'kind' => 'project', 'status' => 'published', 'stale' => true, 'name' => 'C'],
    ['uuid' => 'd', 'kind' => 'task', 'status' => 'published', 'stale' => false, 'name' => 'D'],
    ['uuid' => 'e', 'kind' => 'project', 'status' => 'archived', 'stale' => false, 'name' => 'E'],
];
$projects = S::eligible_projects($items);
verify(count($projects) === 1 && $projects['a']['title'] === 'A', 'only active published BMS projects');
verify(strlen(S::TABLE) <= 28, 'XMLDB table name length');
