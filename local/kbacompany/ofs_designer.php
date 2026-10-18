<?php
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use local_kbacompany\local\design_service;
use local_kbacompany\local\product_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$object = optional_param('object', '', PARAM_RAW_TRIMMED);
$action = optional_param('action', '', PARAM_ALPHA);

// Compatibility route. All GET traffic now lands in the unified OFS Workspace.
if ($action === '' || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    redirect(new moodle_url('/local/kbacompany/ofs.php', [
        'uuid' => $uuid, 'mode' => 'design', 'object' => $object,
    ]));
}

if (!product_service::can_manage($uuid, (int)$USER->id)) {
    throw new required_capability_exception($context, 'local/kbacompany:manageorganization', 'nopermissions', '');
}
require_sesskey();

if ($action === 'create') {
    $object = design_service::command($uuid, (int)$USER->id, 'create', [
        'source' => optional_param('source', 'bms_ofs', PARAM_ALPHANUMEXT),
        'name' => optional_param('name', get_string('ofsdesigner_defaultname', 'local_kbacompany'), PARAM_TEXT),
    ]);
} else if ($action === 'visualsettings') {
    $object = required_param('object', PARAM_RAW_TRIMMED);
    design_service::command($uuid, (int)$USER->id, 'visualsettings', [
        'object' => $object,
        'revision' => required_param('revision', PARAM_INT),
        'companyproduct' => optional_param('companyproduct', '', PARAM_TEXT),
        'companyproductuuid' => optional_param('companyproductuuid', '', PARAM_RAW_TRIMMED),
    ]);
} else if ($action === 'visualnode') {
    $object = required_param('object', PARAM_RAW_TRIMMED);
    design_service::command($uuid, (int)$USER->id, 'visualnode', [
        'object' => $object,
        'revision' => required_param('revision', PARAM_INT),
        'node' => required_param('node', PARAM_RAW_TRIMMED),
        'name' => required_param('name', PARAM_TEXT),
        'visualtier' => required_param('visualtier', PARAM_ALPHANUMEXT),
        'product' => optional_param('product', '', PARAM_TEXT),
        'productuuid' => optional_param('productuuid', '', PARAM_RAW_TRIMMED),
    ]);
} else if ($action === 'insert') {
    $object = required_param('object', PARAM_RAW_TRIMMED);
    design_service::command($uuid, (int)$USER->id, 'insert', [
        'object' => $object,
        'revision' => required_param('revision', PARAM_INT),
        'code' => required_param('code', PARAM_TEXT),
        'name' => required_param('name', PARAM_TEXT),
        'type' => 'department',
        'layout_parent' => optional_param('layout_parent', '', PARAM_RAW_TRIMMED),
        'reporting_parent' => optional_param('layout_parent', '', PARAM_RAW_TRIMMED),
        'sortorder' => optional_param('sortorder', 100, PARAM_INT),
        'color' => '#E8B632',
        'visualtier' => 'department',
        'product' => optional_param('product', '', PARAM_TEXT),
    ]);
} else {
    throw new moodle_exception('productinvalidinput', 'local_kbacompany');
}

redirect(new moodle_url('/local/kbacompany/ofs.php', [
    'uuid' => $uuid, 'mode' => 'design', 'object' => $object,
]), get_string('productsaved', 'local_kbacompany'));
