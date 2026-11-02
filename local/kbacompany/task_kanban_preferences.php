<?php
/** Saved Kanban layout only. Never mutates task status or company workflows. */
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\company_context_service;
use local_kbacompany\local\task_kanban_preferences_service as prefs;
use local_kbacompany\local\task_ui_service as ui;
use local_kbacompany\local\task_service;

require_login();
require_capability('local/kbacompany:viewowncompany', context_system::instance());
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    throw new moodle_exception('taskcenter_post', 'local_kbacompany');
}
require_sesskey();
$company = required_param('uuid', PARAM_RAW_TRIMMED);
$actor = (int)$USER->id;
company_context_service::context($company, $actor);
$labels = optional_param_array('labels', [], PARAM_RAW_TRIMMED);
$orders = optional_param_array('orders', [], PARAM_INT);
$visible = optional_param_array('visible', [], PARAM_INT);
$mapping = optional_param_array('mapping', [], PARAM_ALPHANUMEXT);
$limits = optional_param_array('limits', [], PARAM_INT);
$groupby = optional_param('groupby', 'none', PARAM_ALPHA);
// Input controls are namespaced by whitelisted slot IDs. Ignore arbitrary keys.
$columns = [];
foreach (prefs::STATUSES as $id) {
    $columns[$id] = [
        'label'=>(string)($labels[$id] ?? ''),
        'order'=>(int)($orders[$id] ?? 0),
        'visible'=>!empty($visible[$id]),
    ];
}
// Enabling no columns will leave the first column available; mapping never hides tasks.
if (optional_param('reset', 0, PARAM_INT) === 1) {
    $defaults = prefs::defaults();
    prefs::save($company, $actor, $defaults['columns'], $defaults['mapping']);
} else {
    prefs::save($company, $actor, $columns, $mapping, $groupby, $limits);
}
$manager = task_service::can_manage($company, $actor);
$params = ['scope'=>task_service::normalise_scope(optional_param('scope', 'roles', PARAM_ALPHA), $manager),
    'display'=>'kanban'];
foreach (['q'=>PARAM_RAW_TRIMMED, 'status'=>PARAM_ALPHA, 'stream'=>PARAM_ALPHA,
    'sourcekind'=>PARAM_ALPHA, 'battleplan'=>PARAM_RAW_TRIMMED, 'signal'=>PARAM_ALPHA,
    'project'=>PARAM_RAW_TRIMMED] as $name=>$type) {
    $value = optional_param($name, '', $type);
    if ($value !== '') { $params[$name] = $value; }
}
$assignee = optional_param('assignee', 0, PARAM_INT);
if ($assignee > 0) { $params['assignee'] = $assignee; }
redirect(ui::url($company, $params), get_string('bms_task1846_saved', 'local_kbacompany'));
