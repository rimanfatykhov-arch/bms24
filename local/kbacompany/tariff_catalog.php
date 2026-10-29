<?php
// Read-only future BMS Cloud catalog contract; company admins only.
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\access_role_service;
use local_kbacompany\local\bms_page_shell_service;
use local_kbacompany\local\bms_tariff_catalog_service as catalog;
use local_kbacompany\local\company_context_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = company_context_service::normalise_uuid(required_param('uuid', PARAM_RAW_TRIMMED));
access_role_service::require_administrator($uuid, (int)$USER->id);
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacompany/tariff_catalog.php', ['uuid' => $uuid]));
$PAGE->set_title(get_string('bmstariff_title', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));
$shell = bms_page_shell_service::begin($uuid, (int)$USER->id, 'settings',
    get_string('bmstariff_title', 'local_kbacompany'));
$state = catalog::company_status($uuid, (int)$USER->id);
$contract = catalog::contract();
echo html_writer::tag('h2', get_string('bmstariff_title', 'local_kbacompany'), ['class' => 'h3']);
echo html_writer::div(get_string('bmstariff_notice', 'local_kbacompany'), 'alert alert-info');
echo html_writer::tag('h3', get_string('bmstariff_products', 'local_kbacompany'), ['class' => 'h5 mt-3']);
echo html_writer::start_div('row');
foreach ($contract['products'] as $product) {
    echo html_writer::start_div('col-md-6 mb-3');
    echo html_writer::start_div('card card-body h-100');
    echo html_writer::tag('h4', s($product['title']), ['class' => 'h5']);
    echo html_writer::tag('p', get_string('bmstariff_planned', 'local_kbacompany'),
        ['class' => 'text-muted mb-1']);
    echo html_writer::tag('p', s(implode(' · ', $product['features'])), ['class' => 'small mb-0']);
    echo html_writer::end_div();
    echo html_writer::end_div();
}
echo html_writer::end_div();
echo html_writer::tag('h3', get_string('bmstariff_offertypes', 'local_kbacompany'), ['class' => 'h5 mt-3']);
foreach ($contract['offer_types'] as $offer => $fulfillment) {
    echo html_writer::div(s($offer . ' → ' . $fulfillment), 'small py-1');
}
echo html_writer::div(get_string('bmstariff_offer_note', 'local_kbacompany'), 'alert alert-info mt-2');
echo html_writer::tag('h3', get_string('bmstariff_limits', 'local_kbacompany'), ['class' => 'h5']);
echo html_writer::div(s(implode(' · ', $contract['limits'])), 'card card-body mb-3');
echo html_writer::tag('h3', get_string('bmstariff_company', 'local_kbacompany'), ['class' => 'h5']);
echo html_writer::div(s(get_string('bmstariff_mode', 'local_kbacompany') . ': ' . $state['mode'] .
    ' · ' . get_string('bmstariff_limitunknown', 'local_kbacompany')),
    'alert alert-secondary');
echo html_writer::link(new moodle_url('/local/kbacompany/access_roles.php', ['uuid' => $uuid]),
    get_string('bmsacl_title', 'local_kbacompany'), ['class' => 'btn btn-outline-secondary']);
bms_page_shell_service::finish($shell);
