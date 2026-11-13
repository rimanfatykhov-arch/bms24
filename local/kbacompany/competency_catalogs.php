<?php
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\company_context_service;
use local_kbacompany\local\competency_catalog_service;
use local_kbacompany\local\permission_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$company = company_context_service::context($uuid, (int)$USER->id);
// Only site administrators may discover and authorise a system catalog for a company.
$canbind = is_siteadmin() && permission_service::can_manage_people($uuid, (int)$USER->id);
$url = new moodle_url('/local/kbacompany/competency_catalogs.php', ['uuid' => $uuid]);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    if (!$canbind) {
        throw new required_capability_exception($context, 'moodle/site:config', 'nopermissions', '');
    }
    competency_catalog_service::bind_framework($uuid, (int)$USER->id,
        required_param('frameworkid', PARAM_INT), 'company');
    redirect($url, get_string('companycatalog_saved', 'local_kbacompany'));
}
$linked = competency_catalog_service::frameworks($uuid, (int)$USER->id);
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_title(get_string('companycatalog_title', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));
$shell = \local_kbacompany\local\bms_page_shell_service::begin($uuid, (int)$USER->id, 'people',
    get_string('companycatalog_title', 'local_kbacompany'));
echo html_writer::link(new moodle_url('/local/kbacompany/people.php', ['uuid' => $uuid]),
    get_string('companycatalog_back', 'local_kbacompany'));
echo $OUTPUT->heading(get_string('companycatalog_title', 'local_kbacompany'), 1);
echo html_writer::tag('p', get_string('companycatalog_intro', 'local_kbacompany'));
if ($linked) {
    $table = new html_table();
    $table->head = [get_string('name'), get_string('idnumber')];
    foreach ($linked as $framework) {
        $table->data[] = [format_string($framework['shortname']), s($framework['idnumber'])];
    }
    echo html_writer::table($table);
} else {
    echo $OUTPUT->notification(get_string('companycatalog_empty', 'local_kbacompany'), 'info');
}
if ($canbind) {
    $options = [];
    foreach ($DB->get_records('competency_framework', null, 'shortname ASC, id ASC') as $framework) {
        $options[$framework->id] = $framework->shortname . ' (' . $framework->idnumber . ')';
    }
    if ($options) {
        echo html_writer::start_tag('form', ['method' => 'post', 'action' => $url->out(false)]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::label(get_string('companycatalog_select', 'local_kbacompany'), 'frameworkid');
        echo html_writer::select($options, 'frameworkid', '', ['' => get_string('choosedots')],
            ['id' => 'frameworkid', 'required' => 'required']);
        echo html_writer::tag('button', get_string('companycatalog_bind', 'local_kbacompany'),
            ['type' => 'submit', 'class' => 'btn btn-primary']);
        echo html_writer::end_tag('form');
    } else {
        echo $OUTPUT->notification(get_string('companycatalog_nosystem', 'local_kbacompany'), 'info');
    }
} else {
    echo html_writer::tag('p', get_string('companycatalog_admin', 'local_kbacompany'));
}
\local_kbacompany\local\bms_page_shell_service::finish($shell);
