<?php
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\management_cycle_template_service as cycles;
use local_kbacompany\local\management_cycle_ui_service as ui;
use local_kbacompany\local\management_cycle_rollover_service as rollover;
use local_kbacompany\local\management_a3_service;
use local_kbacompany\local\management_cycle_2_service as cycle2;
use local_kbacompany\local\bms_workspace_ux_service;
use local_kbacompany\local\bms_page_shell_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$actor = (int)$USER->id;
$cycleuuid = optional_param('cycle', '', PARAM_RAW_TRIMMED);
$view = optional_param('view', $cycleuuid === '' ? 'list' : 'detail', PARAM_ALPHA);
$tab = optional_param('tab', 'overview', PARAM_ALPHANUM);
$action = optional_param('action', '', PARAM_ALPHA);
if (!in_array($view, ['list','create','detail','edit','action'], true) ||
        !in_array($tab, ['overview','checkpoints','execution','a3','history'], true)) { throw new moodle_exception('invalidparameter'); }
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET','HEAD','POST'], true)) {
    http_response_code(405);
    throw new moodle_exception('invalidparameter');
}
// Company access and current capabilities are resolved before pickers, details or any write.
\local_kbacompany\local\company_context_service::context($uuid, $actor);
\local_kbacompany\local\access_role_service::require_permission($uuid, $actor, 'management_cycle', 'view');
// GET cannot open editing screens without corresponding action permission.
if ($method !== 'POST' && in_array($view, ['create', 'edit', 'action'], true)) {
    \local_kbacompany\local\access_role_service::require_permission($uuid, $actor,
        'management_cycle', $view === 'create' ? 'create' : 'edit');
}
$cycle = $cycleuuid === '' ? null : cycles::detail($uuid, $actor, $cycleuuid);
if (in_array($view,['detail','edit','action'],true) && !$cycle) { throw new moodle_exception('mcycle_not_found','local_kbacompany'); }
if ($view === 'edit' && !$cycle['actions']['edit']) { throw new moodle_exception('mcu_edit_draft','local_kbacompany'); }
$values = $cycle ?? [];
$errors = []; $extra = []; $actionerror = '';
$templatecode = $cycle['templatecode'] ?? optional_param('templatecode', '', PARAM_ALPHANUMEXT);
$options = null;
if ($templatecode !== '' && !isset(cycles::templates()[$templatecode])) { throw new moodle_exception('mcycle_invalid_template','local_kbacompany'); }
if ($view === 'create' && $templatecode !== '') {
    // Suggestions are visible editable dates, never a constraint imposed by a template's nominal days.
    $today = new DateTimeImmutable(userdate(time(), '%Y-%m-%d'), new DateTimeZone('UTC'));
    $start = $today; $end = $today;
    if ($templatecode === 'week') { $start = $today->modify('monday this week'); $end = $start->modify('+6 days'); }
    else if ($templatecode === 'month') { $start = $today->modify('first day of this month'); $end = $start->modify('last day of this month'); }
    else if ($templatecode === 'quarter') {
        $start = new DateTimeImmutable($today->format('Y').'-'.sprintf('%02d', 1+3*(int)floor(((int)$today->format('n')-1)/3)).'-01',new DateTimeZone('UTC'));
        $end = $start->modify('+3 months -1 day');
    } else if ($templatecode === 'year') { $start = $today->modify('first day of January this year'); $end = $today->modify('last day of December this year'); }
    else { $end = $start->modify('+'.(cycles::templates()[$templatecode]['days']-1).' days'); }
    $values = ['templatecode'=>$templatecode,'title'=>'','priority'=>'','outcome'=>'','periodstart'=>$start->format('Y-m-d'),
        'periodend'=>$end->format('Y-m-d'),'ownerid'=>0,'curatorid'=>0,'indicatoruuids'=>[],'parentuuid'=>'',
        'requestkey'=>bin2hex(random_bytes(16))];
}
if ($method === 'POST') {
    require_sesskey();
    \local_kbacompany\local\access_role_service::require_permission($uuid, $actor,
        'management_cycle', $action === 'create' ? 'create' : 'edit');
    if ($action === 'create' || $action === 'save') {
        if (($action === 'create' && $cycle) || ($action === 'save' && !$cycle)) { throw new moodle_exception('invalidparameter'); }
        $view = $action === 'save' ? 'edit' : 'create';
        $values = ['templatecode'=>$templatecode,'indicatoruuids'=>optional_param_array('indicatoruuids',[],PARAM_RAW_TRIMMED),
            'ownerid'=>optional_param('ownerid',0,PARAM_INT),'curatorid'=>optional_param('curatorid',0,PARAM_INT),
            'parentuuid'=>optional_param('parentuuid','',PARAM_RAW_TRIMMED),
            'requestkey'=>optional_param('requestkey','',PARAM_ALPHANUM), 'edit_token'=>optional_param('expected','',PARAM_ALPHANUM)];
        foreach (['title','priority','outcome','periodstart','periodend'] as $field) { $values[$field] = optional_param($field,'',PARAM_TEXT); }
        if ($templatecode === '') { throw new moodle_exception('mcycle_invalid_template','local_kbacompany'); }
        $errors = cycles::validation_errors($uuid,$actor,$values,$cycleuuid);
        if ($action === 'create' && !preg_match('/^[a-f0-9]{32}$/D', $values['requestkey'])) {
            $errors['_form'] = get_string('mcu_stale','local_kbacompany');
        }
        if (!$errors) {
            try {
                if ($action === 'save') { cycles::update_draft($uuid,$actor,$cycleuuid,$values['edit_token'],$values); $saved = $cycleuuid; }
                else { $saved = cycles::create($uuid,$actor,$values); }
            } catch (moodle_exception $e) { $errors['_form'] = $e->getMessage(); }
            if (!$errors) { redirect(ui::url($uuid,['cycle'=>$saved]),get_string('mcu_saved','local_kbacompany')); }
        }
    } else {
        $view = 'action';
        if (!$cycle || !in_array($action,['start','close','cancel','checkpoint','checkpointmeeting','rollover','createchild'],true)) {
            throw new moodle_exception('invalidparameter');
        }
        $values = ['expected'=>optional_param('expected','',PARAM_ALPHANUM),'checkpoint'=>optional_param('checkpoint','',PARAM_RAW_TRIMMED),
            'result'=>optional_param('result','',PARAM_TEXT),'title'=>optional_param('title','',PARAM_TEXT),
            'periodstart'=>optional_param('periodstart','',PARAM_TEXT),'periodend'=>optional_param('periodend','',PARAM_TEXT),
            'carrytasks'=>optional_param_array('carrytasks',[],PARAM_RAW_TRIMMED),'requestkey'=>optional_param('requestkey','',PARAM_ALPHANUM)];
        if (in_array($action,['checkpoint','checkpointmeeting'],true)) {
            $checkpoint = $DB->get_record('local_kbacompany_mcyclecp',
                ['companyuuid'=>$uuid,'cycleuuid'=>$cycleuuid,'uuid'=>$values['checkpoint']], '*', MUST_EXIST);
        }
        try {
            if (!optional_param('confirmed',false,PARAM_BOOL)) { throw new moodle_exception('mcu_confirm_required','local_kbacompany'); }
            if (in_array($action,['start','close','cancel'],true)) {
                if ($values['expected'] === '') { throw new moodle_exception('mcu_stale','local_kbacompany'); }
                cycles::transition($uuid,$actor,$cycleuuid,['start'=>'active','close'=>'closed','cancel'=>'cancelled'][$action],$values['expected']);
                $destination = ui::url($uuid,['cycle'=>$cycleuuid]);
            } else {
                if (empty($cycle['actions'][$action])) { throw new moodle_exception('mcycle_invalid_status','local_kbacompany'); }
                $destination = ui::url($uuid,['cycle'=>$cycleuuid,'tab'=>'checkpoints']);
                if ($action === 'checkpoint') { cycles::complete_checkpoint($uuid,$actor,$checkpoint->uuid,$values['result']); }
                else if ($action === 'checkpointmeeting') {
                    $meeting = cycles::checkpoint_meeting($uuid,$actor,$checkpoint->uuid);
                    $destination = new moodle_url('/local/kbacompany/meetings.php',['uuid'=>$uuid,'meeting'=>$meeting]);
                } else if ($action === 'rollover') {
                    $saved = rollover::create_next($uuid,$actor,$cycleuuid,$values['title'],$values['carrytasks']);
                    $destination = ui::url($uuid,['cycle'=>$saved]);
                } else if ($action === 'createchild') {
                    if (!preg_match('/^[a-f0-9]{32}$/D', $values['requestkey'])) { throw new moodle_exception('mcu_stale','local_kbacompany'); }
                    $saved = rollover::create_child($uuid,$actor,$cycleuuid,$values['periodstart'],$values['periodend'],$values['title'],$values['requestkey']);
                    $destination = ui::url($uuid,['cycle'=>$saved]);
                }
            }
        } catch (moodle_exception $e) { $actionerror = $e->getMessage(); }
        if ($actionerror === '') { redirect($destination); }
    }
}
if ($view === 'action') {
    if (!in_array($action,['start','close','cancel','checkpoint','checkpointmeeting','rollover','createchild'],true)) { throw new moodle_exception('invalidparameter'); }
    if (empty($cycle['actions'][$action]) && $actionerror === '') { throw new moodle_exception('mcu_owner_action','local_kbacompany'); }
    if (in_array($action,['checkpoint','checkpointmeeting'],true)) {
        $values['checkpoint'] = optional_param('checkpoint','',PARAM_RAW_TRIMMED);
        $extra['checkpoint'] = $DB->get_record('local_kbacompany_mcyclecp',
            ['companyuuid'=>$uuid,'cycleuuid'=>$cycleuuid,'uuid'=>$values['checkpoint']], '*', MUST_EXIST);
    }
    if ($action === 'rollover' || $action === 'createchild') {
        $extra['preview'] = rollover::preview($uuid,$actor,$cycleuuid);
        if ($method !== 'POST') {
            $values['requestkey'] = bin2hex(random_bytes(16));
            $values['title'] = ''; $values['periodstart'] = $cycle['periodstart'];
            $childcode = $extra['preview']['child_template'];
            $days = cycles::templates()[$childcode]['days'] ?? 7;
            $end = (new DateTimeImmutable($cycle['periodstart'],new DateTimeZone('UTC')))->modify('+'.($days-1).' days')->format('Y-m-d');
            $values['periodend'] = min($end,$cycle['periodend']);
        }
    }
}
$PAGE->set_context($context);
$PAGE->set_url(ui::url($uuid,['cycle'=>$cycleuuid,'view'=>$view,'tab'=>$tab]));
$PAGE->set_title(get_string('mcycle_title','local_kbacompany'));
$PAGE->set_heading(get_string('pluginname','local_kbacompany'));
$shell = bms_page_shell_service::begin($uuid,$actor,'managementcycles',get_string('mcycle_title','local_kbacompany'));
echo bms_workspace_ux_service::management_nav($uuid,'cycles');
echo html_writer::start_div('bms-mcycle');
if ($view === 'list') {
    $filters = ['q'=>optional_param('q','',PARAM_TEXT),'status'=>optional_param('status','',PARAM_ALPHA),
        'templatecode'=>optional_param('templatecode','',PARAM_ALPHANUMEXT),'ownerid'=>optional_param('ownerid',0,PARAM_INT),
        'from'=>optional_param('from','',PARAM_TEXT),'to'=>optional_param('to','',PARAM_TEXT)];
    $model = cycles::listing($uuid,$actor,$filters,max(0,optional_param('page',0,PARAM_INT)));
    $options = cycles::form_options($uuid,$actor,'year');
    echo ui::listing($uuid,$model,$filters,$options['members'],
        \local_kbacompany\local\access_role_service::can($uuid, $actor, 'management_cycle', 'create')
            ? cycle2::cadence_overview($uuid,$actor) : [],
        \local_kbacompany\local\access_role_service::can($uuid, $actor, 'management_cycle', 'create'));
} else if ($view === 'create' && $templatecode === '') { echo ui::template_picker($uuid); }
else if ($view === 'create' || $view === 'edit') {
    $options = cycles::form_options($uuid,$actor,$templatecode,$cycleuuid);
    echo ui::form($uuid,$options,$values,$errors,$cycleuuid);
} else if ($view === 'action') { echo ui::action_form($uuid,$cycle,$action,$values,$extra,$actionerror); }
else {
    if ($tab === 'overview') { $extra['management2'] = cycle2::context($uuid,$actor,$cycleuuid); }
    if ($tab === 'a3') { $extra['a3'] = management_a3_service::list_for_cycle($uuid,$actor,$cycleuuid); }
    if ($tab === 'history') { $extra['history'] = cycles::history($uuid,$actor,$cycleuuid); }
    echo ui::detail($uuid,$cycle,$tab,$extra);
}
echo html_writer::end_div();
bms_page_shell_service::finish($shell);
