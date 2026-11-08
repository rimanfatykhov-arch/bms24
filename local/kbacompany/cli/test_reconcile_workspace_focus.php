<?php
/** Pure widget filtering regression; no Moodle, database or permissions granted. */
define('MOODLE_INTERNAL', true);
require_once(__DIR__ . '/../classes/local/bms_workspace_widget_service.php');
require_once(__DIR__ . '/../classes/local/bms_workspace_focus_service.php');

use local_kbacompany\local\bms_workspace_focus_service as Focus;
use local_kbacompany\local\bms_workspace_widget_service as Widgets;

$visible = ['company', 'tasks', 'attention', 'unknown', 'management', 'today', 'architecture'];
$cases = [
    'personal' => ['tasks', 'attention', 'today'],
    'management' => ['attention', 'management'],
    'owner' => ['company', 'attention', 'management', 'architecture'],
    'all' => array_values(array_intersect($visible, Widgets::DEFAULT_ORDER)),
    'unknown' => array_values(array_intersect($visible, Widgets::DEFAULT_ORDER)),
    '0' => array_values(array_intersect($visible, Widgets::DEFAULT_ORDER)),
];
foreach ($cases as $mode => $expected) {
    if (Focus::visible($visible, (string)$mode) !== $expected || Focus::visible([], (string)$mode) !== []) {
        throw new RuntimeException('Workspace filter changed for mode ' . $mode);
    }
}
if (Focus::visible(['tasks', 'tasks'], 'personal') !== ['tasks', 'tasks'] ||
        Focus::select('owner', false, false) !== 'all') {
    throw new RuntimeException('Widget input semantics or denied mode changed');
}
echo "PASS: six workspace modes, empty input, order/duplicates and denied mode\n";
