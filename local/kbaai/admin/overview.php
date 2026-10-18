<?php
// KBA AI Assessor administration overview.

require_once('../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_kbaai_overview');

$plugininfo = \core_plugin_manager::instance()->get_plugin_info('local_kbaai');
$release = ($plugininfo && !empty($plugininfo->release)) ? (string)$plugininfo->release : '0.9.0';
$versiondisk = ($plugininfo && !empty($plugininfo->versiondisk)) ? (string)$plugininfo->versiondisk : '2026081602';
$versiondb = ($plugininfo && !empty($plugininfo->versiondb))
    ? (string)$plugininfo->versiondb
    : get_string('versionnotinstalled', 'local_kbaai');

$defaultprovider = (string)(get_config('local_kbaai', 'defaultprovider') ?: 'mock');
$folderid = trim((string)get_config('local_kbaai', 'yandexfolderid'));
$apikeyconfigured = !empty($CFG->kbaai_yandex_apikey) || trim((string)get_config('local_kbaai', 'yandexapikey')) !== '';

$mappingready = false;
$mappingmessage = get_string('schemanotreadyoverview', 'local_kbaai');
try {
    $diagnosis = (new \local_kbaai\source\kbaelement_adapter())->diagnose();
    $mappingready = !empty($diagnosis['ready']);
    $mappingmessage = $mappingready
        ? get_string('schemareadyoverview', 'local_kbaai')
        : get_string('schemanotreadyoverview', 'local_kbaai');
} catch (\Throwable $e) {
    $mappingmessage = get_string('schemacheckfailed', 'local_kbaai');
}

$docxok = false;
$xlsxok = false;
try {
    $docx = (new \local_kbaai\service\docx_extractor())->diagnose();
    $docxok = !empty($docx['ok']);
} catch (\Throwable $e) {
    $docxok = false;
}
try {
    $xlsx = (new \local_kbaai\service\xlsx_extractor())->diagnose();
    $xlsxok = !empty($xlsx['ok']);
} catch (\Throwable $e) {
    $xlsxok = false;
}

$providerurl = new moodle_url('/local/kbaai/provider_test_v082.php');
$fullproviderurl = new moodle_url('/local/kbaai/provider_test_v082.php', array(
    'run' => 1,
    'full' => 1,
    'provider' => 'yandex',
    'sesskey' => sesskey(),
));
$quickproviderurl = new moodle_url('/local/kbaai/provider_test_v082.php', array(
    'run' => 1,
    'provider' => 'yandex',
    'sesskey' => sesskey(),
));
$diagnosticsurl = new moodle_url('/local/kbaai/diagnostics_v082.php');

$PAGE->set_title(get_string('adminoverview', 'local_kbaai'));
$PAGE->set_heading(get_string('admincategory', 'local_kbaai'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('admincategory', 'local_kbaai'));
echo html_writer::tag('p', get_string('adminoverviewintro', 'local_kbaai'), array('class' => 'lead'));

// Version card.
$versiontable = new html_table();
$versiontable->attributes['class'] = 'generaltable table-sm mb-0';
$versiontable->data = array(
    array(get_string('productversion', 'local_kbaai'), s($release)),
    array(get_string('plugincomponent', 'local_kbaai'), 'local_kbaai'),
    array(get_string('versionondisk', 'local_kbaai'), s($versiondisk)),
    array(get_string('versionindb', 'local_kbaai'), s($versiondb)),
);
echo html_writer::start_div('card mb-4');
echo html_writer::start_div('card-body');
echo html_writer::tag('h3', get_string('versioninfoheading', 'local_kbaai'), array('class' => 'h5'));
echo html_writer::table($versiontable);
echo html_writer::end_div();
echo html_writer::end_div();

// Status cards.
$statusitems = array(
    array(
        'title' => get_string('overviewprovider', 'local_kbaai'),
        'ok' => $defaultprovider === 'yandex' ? ($folderid !== '' && $apikeyconfigured) : true,
        'text' => $defaultprovider === 'yandex'
            ? (($folderid !== '' && $apikeyconfigured)
                ? get_string('yandexconfigured', 'local_kbaai')
                : get_string('yandexnotconfigured', 'local_kbaai'))
            : get_string('mockactiveoverview', 'local_kbaai'),
    ),
    array(
        'title' => get_string('overviewintegration', 'local_kbaai'),
        'ok' => $mappingready,
        'text' => $mappingmessage,
    ),
    array(
        'title' => get_string('overviewdocuments', 'local_kbaai'),
        'ok' => $docxok && $xlsxok,
        'text' => ($docxok && $xlsxok)
            ? get_string('localparsersready', 'local_kbaai')
            : get_string('localparsersnotready', 'local_kbaai'),
    ),
);

echo html_writer::tag('h2', get_string('systemstatus', 'local_kbaai'), array('class' => 'h4'));
echo html_writer::start_div('row');
foreach ($statusitems as $item) {
    echo html_writer::start_div('col-md-4 mb-3');
    echo html_writer::start_div('card h-100');
    echo html_writer::start_div('card-body');
    $badgeclass = $item['ok'] ? 'badge badge-success' : 'badge badge-warning';
    $badgetext = $item['ok'] ? get_string('statusready', 'local_kbaai') : get_string('statusattention', 'local_kbaai');
    echo html_writer::tag('h3', s($item['title']) . ' ' . html_writer::span($badgetext, $badgeclass), array('class' => 'h5'));
    echo html_writer::tag('p', s($item['text']), array('class' => 'mb-0'));
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::end_div();
}
echo html_writer::end_div();

// Quick actions. The full diagnostic button is deliberately always visible here.
echo html_writer::start_div('card mb-4');
echo html_writer::start_div('card-body');
echo html_writer::tag('h2', get_string('quickactions', 'local_kbaai'), array('class' => 'h4'));
echo html_writer::start_div('d-flex flex-wrap');
echo html_writer::link($fullproviderurl, get_string('runprovideralltest', 'local_kbaai'), array('class' => 'btn btn-primary mr-2 mb-2'));
echo html_writer::link($quickproviderurl, get_string('runproviderquicktest', 'local_kbaai'), array('class' => 'btn btn-secondary mr-2 mb-2'));
echo html_writer::link($providerurl, get_string('openproviderdiagnostics', 'local_kbaai'), array('class' => 'btn btn-outline-secondary mr-2 mb-2'));
echo html_writer::link($diagnosticsurl, get_string('openintegrationdiagnostics', 'local_kbaai'), array('class' => 'btn btn-outline-secondary mb-2'));
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();

// Section navigation cards.
$sections = array(
    array('local_kbaai_general', 'adminsectiongeneral', 'adminsectiongeneral_desc'),
    array('local_kbaai_yandex', 'adminsectionyandex', 'adminsectionyandex_desc'),
    array('local_kbaai_files', 'adminsectionfiles', 'adminsectionfiles_desc'),
    array('local_kbaai_supervisor', 'adminsectionsupervisor', 'adminsectionsupervisor_desc'),
    array('local_kbaai_integration', 'adminsectionintegration', 'adminsectionintegration_desc'),
);

echo html_writer::tag('h2', get_string('settingssections', 'local_kbaai'), array('class' => 'h4'));
echo html_writer::start_div('row');
foreach ($sections as $section) {
    $url = new moodle_url('/admin/settings.php', array('section' => $section[0]));
    echo html_writer::start_div('col-md-6 mb-3');
    echo html_writer::start_div('card h-100');
    echo html_writer::start_div('card-body');
    echo html_writer::tag('h3', get_string($section[1], 'local_kbaai'), array('class' => 'h5'));
    echo html_writer::tag('p', get_string($section[2], 'local_kbaai'));
    echo html_writer::link($url, get_string('opensection', 'local_kbaai'), array('class' => 'btn btn-outline-primary'));
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::end_div();
}
echo html_writer::end_div();

echo $OUTPUT->footer();
