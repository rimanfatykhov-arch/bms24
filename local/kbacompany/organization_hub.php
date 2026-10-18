<?php
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use local_kbacompany\local\company_service;
use local_kbacompany\local\organization_service;
use local_kbacompany\local\organizing_board_service;
use local_kbacompany\local\position_service;
use local_kbacompany\local\product_service;
use local_kbacompany\local\ofs_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$company = company_service::company_context($uuid, (int)$USER->id);
$organization = organization_service::organization_context($uuid, (int)$USER->id);
$board = organizing_board_service::board_context($uuid, (int)$USER->id);
$positions = position_service::positions_context($uuid, (int)$USER->id);
$ofs = ofs_service::context($uuid, (int)$USER->id);
$canmanage = product_service::can_manage($uuid, (int)$USER->id);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacompany/organization_hub.php', ['uuid' => $uuid]));
// BMS page shell adapter selects the presentation layout.
$PAGE->set_title(get_string('orghub_title', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));

$bmsshell = \local_kbacompany\local\bms_page_shell_service::begin($uuid, (int)$USER->id, 'organization', get_string('orghub_title', 'local_kbacompany'));
echo html_writer::link(new moodle_url('/local/kbacompany/bms_workspace.php', ['uuid' => $uuid]),
    '← ' . get_string('backtocompany', 'local_kbacompany'), ['class' => 'btn btn-outline-primary mb-3']);
echo $OUTPUT->heading(get_string('orghub_title', 'local_kbacompany') . ': ' . format_string($company['name']));
echo html_writer::tag('p', get_string('orghub_intro', 'local_kbacompany'), ['class' => 'lead']);
echo html_writer::start_div('mb-3');
echo html_writer::link(new moodle_url('/local/kbacompany/ofs_designer.php', ['uuid' => $uuid]),
    get_string('ofsdesigner_open', 'local_kbacompany'), ['class' => 'btn btn-primary mr-2']);
if ($canmanage) {
    echo html_writer::link(new moodle_url('/local/kbacompany/ofs_templates.php', ['uuid' => $uuid]),
        get_string('ofstemplates_title', 'local_kbacompany'), ['class' => 'btn btn-outline-primary']);
}
echo html_writer::end_div();
echo local_kbacompany_organization_tabs($uuid, 'overview', $canmanage);

$cards = [
    ['ofs_designer.php', 'ofsdesigner_title', 'ofsdesigner_hub_help', (int)$ofs['summary']['org_units_with_ckp']],
    ['organizing_board.php', 'orghub_board', 'orghub_board_help', (int)$board['summary']['nodes']],
    ['ofs.php', 'orghub_ofs', 'orghub_ofs_help', (int)$ofs['summary']['org_units_with_ckp']],
    ['organization.php', 'orghub_people', 'orghub_people_help', (int)$organization['summary']['employees']],
    ['positions.php', 'orghub_staffing', 'orghub_staffing_help', count($positions['positions'])],
    ['position_os.php', 'orghub_positionos', 'orghub_positionos_help', count($positions['positions'])],
];
if ($canmanage) {
    global $DB;
    $cards[] = ['ofs_templates.php', 'ofstemplates_title', 'ofstemplates_intro',
        count(\local_kbacompany\local\ofs_template_library_service::keys())];
    $cards[] = ['designs.php', 'orghub_projects', 'orghub_projects_help',
        $DB->count_records('local_kbacompany_design', ['companyuuid' => $uuid, 'status' => 'draft'])];
}
echo html_writer::start_tag('div', ['class' => 'kbacompany-section-nav']);
foreach ($cards as [$path, $title, $help, $count]) {
    $body = html_writer::tag('strong', get_string($title, 'local_kbacompany')) .
        html_writer::tag('span', get_string($help, 'local_kbacompany')) .
        html_writer::tag('span', get_string('orghub_count', 'local_kbacompany', $count), ['class' => 'badge badge-light']);
    echo html_writer::link(new moodle_url('/local/kbacompany/' . $path, ['uuid' => $uuid]), $body,
        ['class' => 'kbacompany-section-link kbacompany-hub-card']);
}
echo html_writer::end_tag('div');
\local_kbacompany\local\bms_page_shell_service::finish($bmsshell);
