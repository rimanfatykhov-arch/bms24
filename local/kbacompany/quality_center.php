<?php
/** RC20 consolidated entry to existing canonical governance and corrective action objects. */
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\company_context_service;
use local_kbacompany\local\document_service;
use local_kbacompany\local\policy_service;
use local_kbacompany\local\permission_service;
use local_kbacompany\local\bms_page_shell_service;

require_login();
$company = required_param('uuid', PARAM_RAW_TRIMMED);
$actor = (int)$USER->id;
require_capability('local/kbacompany:viewowncompany', context_system::instance());
company_context_service::context($company, $actor);
$canmanage = permission_service::can_manage_management($company, $actor);
$tab = optional_param('tab', 'documents', PARAM_ALPHA);
if (!in_array($tab, ['documents', 'policies', 'corrections'], true)) {
    throw new moodle_exception('invalidparameter');
}
// Read-only dashboard: never mutates records and never broadens existing object capabilities.
$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/kbacompany/quality_center.php', ['uuid'=>$company, 'tab'=>$tab]));
$PAGE->set_title(get_string('qc20_title', 'local_kbacompany'));
$PAGE->set_heading(get_string('qc20_title', 'local_kbacompany'));
$shell = bms_page_shell_service::begin($company, $actor, 'qualitycenter', get_string('qc20_title', 'local_kbacompany'));
$base = '/local/kbacompany/';
$nav = '';
foreach (['documents'=>'qc20_documents', 'policies'=>'qc20_policies', 'corrections'=>'qc20_corrections'] as $key=>$label) {
    if ($key === 'corrections' && !$canmanage) { continue; }
    $url = new moodle_url($base.'quality_center.php', ['uuid'=>$company, 'tab'=>$key]);
    $nav .= html_writer::link($url, get_string($label, 'local_kbacompany'),
        ['class'=>'btn btn-sm '.($tab === $key ? 'btn-primary' : 'btn-outline-secondary'),
         'aria-current'=>($tab === $key ? 'page' : 'false')]);
}
echo html_writer::tag('h2', get_string('qc20_title', 'local_kbacompany'));
echo html_writer::tag('p', get_string('qc20_intro', 'local_kbacompany'));
echo html_writer::tag('nav', $nav, ['class'=>'d-flex flex-wrap gap-2 mb-3',
    'aria-label'=>get_string('qc20_title', 'local_kbacompany')]);
echo html_writer::tag('h3', get_string(['documents'=>'qc20_documents',
    'policies'=>'qc20_policies', 'corrections'=>'qc20_corrections'][$tab], 'local_kbacompany'));
$cols = [];
$rows = [];
$target = '';
if ($tab === 'documents') {
    $cols = ['qc20_code','qc20_name','qc20_state','qc20_revision'];
    foreach (document_service::master_list($company, $actor) as $entry) {
        $item = $entry['working'] ?: $entry['effective'];
        if (!$item) { continue; }
        $url = new moodle_url($base.'documents.php', ['uuid'=>$company,'document'=>$entry['uuid']]);
        $rows[] = [s($item['code']), html_writer::link($url, s($item['title'])),
            s($item['status']), (string)$item['revision']];
    }
    $target = new moodle_url($base.'documents.php', ['uuid'=>$company]);
} else if ($tab === 'policies') {
    $cols = ['qc20_code','qc20_name','qc20_kind','qc20_state'];
    // Canonical browse already restricts visibility in SQL and returns a paginated list.
    $page = max(0, optional_param('page', 0, PARAM_INT));
    $data = policy_service::browse($company, $actor, [], $page);
    foreach ($data['rows'] as $item) {
        $url = new moodle_url($base.'policies.php', ['uuid'=>$company,'object'=>$item->uuid]);
        $rows[] = [s($item->code), html_writer::link($url, s($item->title)),
            s($item->kind), s($item->status)];
    }
    $target = new moodle_url($base.'policies.php', ['uuid'=>$company]);
} else {
    if (!$canmanage) { throw new required_capability_exception(context_system::instance(),
        'local/kbacompany:manageorganization', 'nopermissions', ''); }
    global $DB;
    $cols = ['qc20_name','qc20_state','qc20_created'];
    // A3 is a management-cycle correction, NOT an unregistered new nonconformity entity.
    // Both scope and output limit are enforced in SQL.
    $items = $DB->get_records_sql('SELECT id, uuid, title, status, timecreated
        FROM {local_kbacompany_a3review} WHERE companyuuid = :company
        ORDER BY timecreated DESC, id DESC', ['company'=>$company], 0, 100);
    foreach ($items as $item) {
        $url = new moodle_url($base.'management_a3.php', ['uuid'=>$company, 'a3'=>$item->uuid]);
        $rows[] = [html_writer::link($url, s($item->title)), s($item->status),
            userdate($item->timecreated, get_string('strftimedate', 'langconfig'))];
    }
    $target = new moodle_url($base.'management_cycles.php', ['uuid'=>$company]);
    echo html_writer::tag('p', get_string('qc20_a3_note','local_kbacompany'));
}
$table = new html_table();
$table->attributes['class'] = 'generaltable w-100';
$table->head = array_map(static function($key) { return get_string($key, 'local_kbacompany'); }, $cols);
$table->data = $rows;
echo $rows ? html_writer::div(html_writer::table($table), 'table-responsive')
    : html_writer::tag('p', get_string('qc20_empty','local_kbacompany'),
        ['class'=>'alert alert-info', 'role'=>'status']);
if ($tab === 'policies' && $data['total'] > $data['pagesize']) {
    echo $OUTPUT->paging_bar($data['total'], $data['page'], $data['pagesize'],
        new moodle_url($base.'quality_center.php', ['uuid'=>$company,'tab'=>'policies']));
}
echo html_writer::link($target, get_string('qc20_open_registry','local_kbacompany'),
    ['class'=>'btn btn-outline-primary mt-3']);
bms_page_shell_service::finish($shell);
