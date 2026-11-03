<?php
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use local_kbacompany\local\bms_page_shell_service;
use local_kbacompany\local\company_context_service;
use local_kbacompany\local\position_ui_service as ui;
use local_kbacompany\local\role_intelligence_service;
use local_kbacompany\local\role_readiness_view;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) {
    throw new moodle_exception('pdu_method', 'local_kbacompany');
}
$uuid = company_context_service::normalise_uuid(required_param('uuid', PARAM_RAW_TRIMMED));
$actor = (int)$USER->id;
$snapshot = role_intelligence_service::snapshot($uuid, $actor);
$status = optional_param('status', '', PARAM_ALPHAEXT);
$gap = optional_param('gap', '', PARAM_ALPHAEXT);
$page = optional_param('page', 0, PARAM_INT);
$model = role_readiness_view::model($snapshot, $status, $gap, $page);
$url = ui::url($uuid, ['status' => $status, 'gap' => $gap], 'role_readiness');
$PAGE->set_url(new moodle_url($url, ['page' => $model['page']]));
$PAGE->set_context($context);
$title = ui::tr('readiness_title');
$PAGE->set_title($title);
$PAGE->set_heading(ui::tr('pluginname'));
$shell = bms_page_shell_service::begin($uuid, $actor, 'positions', $title);
echo $OUTPUT->render_from_template('local_kbacompany/bms24_role_readiness', $model);
echo $OUTPUT->paging_bar($model['total'], $model['page'], role_readiness_view::PAGE_SIZE, $url);
bms_page_shell_service::finish($shell);
