<?php
/** Select an authorized draft Battle Plan for one Corporate implementation task. */
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\company_service;
use local_kbacompany\local\company_context_service;
use local_kbacompany\local\task_provider_service;
use local_kbacompany\local\battle_plan_service;
use local_kbacompany\local\implementation_battle_plan_link_service as link_service;
use local_kbacompany\local\bms_page_shell_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$actor = (int)$USER->id;
$taskid = required_param('taskid', PARAM_INT);
$corporatecompanyid = required_param('companyid', PARAM_INT);
if ($taskid <= 0 || $corporatecompanyid <= 0) { throw new moodle_exception('invalidparameter'); }
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) {
    http_response_code(405); throw new moodle_exception('invalidparameter');
}
if ($method === 'POST') { require_sesskey(); }
// A numeric Corporate company ID is never treated as a BMS company identity.
// Select only companies the actor already belongs to; the bound provider validates
// its independent immutable identity and Corporate's read permissions.
$eligible = [];
$sourceid = 'impltask:' . $taskid;
foreach (company_service::companies_for_user($actor) as $company) {
    $uuid = (string)($company['uuid'] ?? '');
    if ($uuid === '' || empty($company['active'])) { continue; }
    $task = task_provider_service::get_task($uuid, $actor, link_service::PROVIDER, $sourceid);
    if (!link_service::eligible_task($task, $corporatecompanyid, $actor)) { continue; }
    $eligible[$uuid] = ['company' => $company, 'task' => $task];
}
// Do not disclose why access failed (unbound source, unauthorized company, missing task).
if (!$eligible) { throw new moodle_exception('bmsimpl_attach_unavailable', 'local_kbacompany'); }
$selecteduuid = optional_param('uuid', '', PARAM_RAW_TRIMMED);
if ($selecteduuid === '' && count($eligible) === 1) { $selecteduuid = (string)array_key_first($eligible); }
if ($selecteduuid !== '' && !isset($eligible[$selecteduuid])) { throw new moodle_exception('bmsimpl_attach_unavailable', 'local_kbacompany'); }
$plans = [];
if ($selecteduuid !== '') {
    company_context_service::context($selecteduuid, $actor);
    $plans = link_service::select_drafts(battle_plan_service::creation_plan_options($selecteduuid, $actor), $actor);
}
if ($method === 'POST') {
    $planuuid = required_param('plan', PARAM_RAW_TRIMMED);
    if ($selecteduuid === '' || !isset($plans[$planuuid])) {
        throw new moodle_exception('bmsimpl_attach_unavailable', 'local_kbacompany');
    }
    // Authoritative checks: verified BMS source, editable draft, matching
    // assignee, same company and absence of duplicates inside this service.
    battle_plan_service::add_external_task($selecteduuid, $actor, $planuuid, link_service::PROVIDER, $sourceid);
    redirect(new moodle_url('/local/kbacompany/battle_plan.php', [
        'uuid' => $selecteduuid, 'plan' => $planuuid, 'view' => 'detail', 'tab' => 'tasks',
    ]), get_string('bmsimpl_attach_success', 'local_kbacompany'));
}
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacompany/implementation_battle_plan_attach.php',
    ['taskid' => $taskid, 'companyid' => $corporatecompanyid]));
$PAGE->set_title(get_string('bmsimpl_attach_title', 'local_kbacompany'));
$PAGE->set_heading(get_string('bmsimpl_attach_title', 'local_kbacompany'));
if ($selecteduuid !== '') {
    $shell = bms_page_shell_service::begin($selecteduuid, $actor, 'battleplan',
        get_string('bmsimpl_attach_title', 'local_kbacompany'));
} else {
    echo $OUTPUT->header();
}
echo html_writer::tag('h1', get_string('bmsimpl_attach_title', 'local_kbacompany'));
$first = reset($eligible);
echo html_writer::tag('p', get_string('bmsimpl_attach_intro', 'local_kbacompany'), ['class'=>'text-muted']);
echo html_writer::tag('p', format_string((string)$first['task']['title']), ['class'=>'font-weight-bold']);
$actionurl = new moodle_url('/local/kbacompany/implementation_battle_plan_attach.php',
    ['taskid' => $taskid, 'companyid' => $corporatecompanyid]);
if (count($eligible) > 1) {
    echo html_writer::start_tag('form', ['method'=>'get', 'action'=>$actionurl->out(false)]);
    echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'taskid','value'=>$taskid]);
    echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'companyid','value'=>$corporatecompanyid]);
    $companies = [];
    foreach ($eligible as $uuid => $item) { $companies[$uuid] = (string)$item['company']['name']; }
    echo html_writer::tag('label', get_string('bmsimpl_attach_company', 'local_kbacompany'), ['for'=>'implcompany']);
    echo html_writer::select($companies, 'uuid', $selecteduuid, [''=>'—'], ['id'=>'implcompany']);
    echo html_writer::tag('button', get_string('bmsimpl_attach_choose_company', 'local_kbacompany'),
        ['type'=>'submit','class'=>'bms24-ui-btn']);
    echo html_writer::end_tag('form');
}
if ($selecteduuid !== '') {
    if (!$plans) {
        echo html_writer::tag('p', get_string('bmsimpl_attach_no_draft', 'local_kbacompany'),
            ['class'=>'alert alert-info']);
        echo html_writer::link(new moodle_url('/local/kbacompany/battle_plan.php',
            ['uuid'=>$selecteduuid,'view'=>'create','week'=>battle_plan_service::week_start(gmdate('Y-m-d'))]),
            get_string('bmsimpl_attach_create_plan', 'local_kbacompany'), ['class'=>'bms24-ui-btn']);
    } else {
        echo html_writer::start_tag('form', ['method'=>'post', 'action'=>$actionurl->out(false)]);
        foreach (['taskid'=>$taskid,'companyid'=>$corporatecompanyid,'uuid'=>$selecteduuid,'sesskey'=>sesskey()] as $key=>$value) {
            echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>$key,'value'=>$value]);
        }
        echo html_writer::tag('label', get_string('bmsimpl_attach_plan', 'local_kbacompany'), ['for'=>'implplan']);
        $options = [];
        foreach ($plans as $uuid=>$plan) { $options[$uuid] = (string)$plan['label']; }
        echo html_writer::select($options, 'plan', '', [''=>get_string('bmsimpl_attach_select', 'local_kbacompany')],
            ['id'=>'implplan','required'=>'required','class'=>'form-control mb-3']);
        echo html_writer::tag('button', get_string('bmsimpl_attach_submit', 'local_kbacompany'),
            ['type'=>'submit','class'=>'bms24-ui-btn bms24-ui-btn--primary']);
        echo html_writer::end_tag('form');
    }
}
echo html_writer::tag('p', html_writer::link(new moodle_url('/local/kbacorporate/implementation_task.php',
    ['id'=>$taskid]), get_string('bmsimpl_attach_back', 'local_kbacompany')));
if ($selecteduuid !== '') { bms_page_shell_service::finish($shell); }
else { echo $OUTPUT->footer(); }
