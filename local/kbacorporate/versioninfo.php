<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../config.php');

global $CFG;

require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:managecompanies', $context);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacorporate/versioninfo.php'));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('versioninfo', 'local_kbacorporate'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacorporate'));

// Read the version that is physically present on disk. Use a closure so the
// local $plugin variable from version.php cannot leak into the page scope.
$diskinfo = (static function(): stdClass {
    $plugin = new stdClass();
    require(__DIR__ . '/version.php');
    return $plugin;
})();

$diskversion = isset($diskinfo->version) ? (int)$diskinfo->version : 0;
$release = isset($diskinfo->release) ? (string)$diskinfo->release : '';
$component = isset($diskinfo->component) ? (string)$diskinfo->component : 'local_kbacorporate';
$requires = isset($diskinfo->requires) ? (int)$diskinfo->requires : 0;

$dbversionraw = get_config('local_kbacorporate', 'version');
$dbversion = ($dbversionraw === false || $dbversionraw === null || $dbversionraw === '')
    ? 0
    : (int)$dbversionraw;

$issynchronised = ($diskversion > 0 && $dbversion > 0 && $diskversion === $dbversion);
$gamificationstatus = \local_kbacorporate\local\gamification::status();
$bridgestatus = \local_kbacorporate\local\bridge::status();
$moodleruntime = isset($CFG->release) ? (string)$CFG->release : '—';
$phpruntime = PHP_VERSION;
$healthok = $issynchronised && !empty($bridgestatus['schemaok']);

// Human-friendly release number only (e.g. 0.8.1) while keeping full release
// text below as an additional administrator detail.
$shortrelease = $release;
if (preg_match('/^([0-9]+(?:\.[0-9]+)+)/', $release, $matches)) {
    $shortrelease = $matches[1];
}

$maturity = get_string('unknown', 'local_kbacorporate');
if (isset($diskinfo->maturity)) {
    switch ((int)$diskinfo->maturity) {
        case MATURITY_ALPHA:
            $maturity = 'ALPHA';
            break;
        case MATURITY_BETA:
            $maturity = 'BETA';
            break;
        case MATURITY_RC:
            $maturity = 'RC';
            break;
        case MATURITY_STABLE:
            $maturity = 'STABLE';
            break;
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('versioninfo', 'local_kbacorporate'));
echo html_writer::tag('p', get_string('versioninfodesc', 'local_kbacorporate'), ['class' => 'text-muted']);

$table = new html_table();
$table->attributes['class'] = 'generaltable kbacorp-version-info';
$table->head = [get_string('parameter', 'local_kbacorporate'), get_string('value', 'local_kbacorporate')];
$table->data[] = [get_string('modulelabel', 'local_kbacorporate'), get_string('pluginname', 'local_kbacorporate')];
$table->data[] = [get_string('versionlabel', 'local_kbacorporate'), s($shortrelease)];
$table->data[] = [get_string('componentlabel', 'local_kbacorporate'), html_writer::tag('code', s($component))];
$table->data[] = [get_string('diskbuild', 'local_kbacorporate'), html_writer::tag('code', (string)$diskversion)];
$table->data[] = [get_string('moodledbversion', 'local_kbacorporate'), $dbversion > 0 ? html_writer::tag('code', (string)$dbversion) : '—'];
$table->data[] = [get_string('versionstatus', 'local_kbacorporate'), $issynchronised
    ? '✅ ' . get_string('versionsynchronised', 'local_kbacorporate')
    : '⚠️ ' . get_string('versionnotsynchronised', 'local_kbacorporate')];
$table->data[] = [get_string('releaselabel', 'local_kbacorporate'), s($release)];
$table->data[] = [get_string('maturitylabel', 'local_kbacorporate'), s($maturity)];
$table->data[] = [get_string('requiresmoodlebuild', 'local_kbacorporate'), $requires > 0 ? html_writer::tag('code', (string)$requires) : '—'];
$table->data[] = [get_string('moodleruntime', 'local_kbacorporate'), s($moodleruntime)];
$table->data[] = [get_string('phpruntime', 'local_kbacorporate'), s($phpruntime)];
$table->data[] = [get_string('healthstatus', 'local_kbacorporate'), $healthok ? '✅ ' . get_string('healthy', 'local_kbacorporate') : '⚠️ ' . get_string('requiresattention', 'local_kbacorporate')];
$kbaconnecturl = (string)get_config('local_kbacorporate', 'kbaconnectbaseurl');
$bitrixrolefieldcode = (string)get_config('local_kbacorporate', 'bitrixrolefieldcode');
$table->data[] = [get_string('kbaconnectconfigured', 'local_kbacorporate'), $kbaconnecturl !== '' ? '✅ ' . get_string('yes') : '— ' . get_string('no')];
$table->data[] = [get_string('bitrixrolefieldcode', 'local_kbacorporate'), $bitrixrolefieldcode !== '' ? html_writer::tag('code', s($bitrixrolefieldcode)) : '—'];

$gamificationversion = '—';
if (!empty($gamificationstatus['release']) || !empty($gamificationstatus['diskversion'])) {
    $gamificationparts = [];
    if (!empty($gamificationstatus['release'])) {
        $gamificationparts[] = s((string)$gamificationstatus['release']);
    }
    if (!empty($gamificationstatus['diskversion'])) {
        $gamificationparts[] = 'build ' . (int)$gamificationstatus['diskversion'];
    }
    $gamificationversion = implode(' · ', $gamificationparts);
}
$table->data[] = [get_string('gamificationintegration', 'local_kbacorporate'), $gamificationversion];
if (empty($gamificationstatus['enabled'])) {
    $gamificationlabel = '⚪ ' . get_string('gamificationstatusdisabled', 'local_kbacorporate');
} else if (empty($gamificationstatus['available'])) {
    $gamificationlabel = '⚠️ ' . get_string('gamificationstatusmissing', 'local_kbacorporate');
} else if (!empty($gamificationstatus['diskversion']) && !empty($gamificationstatus['dbversion']) &&
        empty($gamificationstatus['synchronised'])) {
    $gamificationlabel = '⚠️ ' . get_string('gamificationstatusmismatch', 'local_kbacorporate');
} else {
    $gamificationlabel = '✅ ' . get_string('gamificationstatusready', 'local_kbacorporate');
}
$table->data[] = [get_string('gamificationintegrationstatus', 'local_kbacorporate'), $gamificationlabel];

echo html_writer::table($table);

if ($issynchronised) {
    echo $OUTPUT->notification(get_string('versionsyncokdesc', 'local_kbacorporate'), 'notifysuccess');
} else {
    $warningdata = (object)[
        'disk' => $diskversion ?: '—',
        'db' => $dbversion ?: '—',
    ];
    echo $OUTPUT->notification(get_string('versionsyncwarningdesc', 'local_kbacorporate', $warningdata), 'notifyproblem');
}

echo html_writer::div(
    $OUTPUT->single_button(new moodle_url('/local/kbacorporate/api.php'), get_string('apidiagnostics', 'local_kbacorporate'), 'get') .
    ' ' . $OUTPUT->single_button(new moodle_url('/local/kbacorporate/companies.php'), get_string('companies', 'local_kbacorporate'), 'get'),
    'kbacorp-toolbar'
);

echo $OUTPUT->footer();
