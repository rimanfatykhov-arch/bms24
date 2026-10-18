<?php
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\document_service as documents;
use local_kbacompany\local\document_ui_service as ui;
use local_kbacompany\local\document_request_service as forms;
use local_kbacompany\local\company_context_service;
use local_kbacompany\local\bms_page_shell_service;

use local_kbacompany\local\bms_workspace_ux_service;

require_login();
// PHP discards the entire multipart body when post_max_size is exceeded.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && empty($_POST) && !empty($_SERVER['CONTENT_LENGTH'])) {
    throw new moodle_exception('dcf_too_large', 'local_kbacompany');
}
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$company = required_param('uuid', PARAM_RAW_TRIMMED);
$actor = (int)$USER->id;
company_context_service::context($company, $actor);
$manager = documents::can_manage($company, $actor);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET','HEAD','POST'], true)) {
    http_response_code(405);
    throw new moodle_exception('document_postrequired','local_kbacompany');
}
$document = optional_param('document', '', PARAM_RAW_TRIMMED);
$view = optional_param('view', $document !== '' ? 'detail' : 'list', PARAM_ALPHA);
$tab = optional_param('tab','overview',PARAM_ALPHA);
$revision = optional_param($method === 'POST' ? 'revision' : 'rev',0,PARAM_INT);
$action = optional_param($method === 'POST' ? 'action' : 'op','',PARAM_ALPHA);
$process = optional_param($method === 'POST' ? 'processuuid' : 'process','',PARAM_RAW_TRIMMED);
$source = optional_param('source','',PARAM_ALPHA);
if ($source === 'stp' && $method !== 'POST') { $view = 'source'; $action = 'createstp'; }
if ($method !== 'POST' && optional_param('action','',PARAM_ALPHA) !== '') {
    // Old mutation URLs must never become GET writes.
    throw new moodle_exception('document_postrequired','local_kbacompany');
}
if (!in_array($view,['list','detail','create','edit','action','source'],true) || $revision < 0) {
    throw new moodle_exception('invalidparameter');
}
$commands = ['addreviewer','submitreview','returndraft','approve','publish','activate','newrevision',
    'cancel','archive','requireack','acknowledge','createrecord','reviewapprove','reviewreject','createstp'];
$upload = []; $uploadattempted = false;
$values = []; $errors = []; $proof = []; $model = null; $receipt = null;
if ($method === 'POST') {
    require_sesskey();
    if (!in_array($action,array_merge(['create','save'],$commands),true)) { throw new moodle_exception('invalidparameter'); }
    if (($action === 'create' || $action === 'createstp') && $document !== '') { throw new moodle_exception('invalidparameter'); }
    $requestkey = required_param('requestkey',PARAM_ALPHANUM);
    $proof = ['requestkey'=>$requestkey];
    $form = forms::verify($requestkey,$company,$actor,$document,$revision,$action,$process);
    $view = $action === 'create' ? 'create' : ($action === 'save' ? 'edit' : ($action === 'createstp' ? 'source' : 'action'));
    // Only declared domain inputs are accepted. Code, source UUID/hash and publication state cannot be supplied by manual forms.
    foreach (['title','doctype','ownerpositionuuid','changereason','reason','comment','effectivedate'] as $field) {
        $values[$field] = optional_param($field,'',PARAM_RAW_TRIMMED);
    }
    foreach (['content','payload'] as $field) { $values[$field] = optional_param($field,'',PARAM_RAW); }
    foreach (['doclevel','reviewerid','userid'] as $field) { $values[$field] = optional_param($field,0,PARAM_INT); }
    $values['confirmed'] = optional_param('confirmed',false,PARAM_BOOL);
    $values['confirmfile'] = optional_param('confirmfile',false,PARAM_BOOL);
    $incoming = $_FILES['documentfile'] ?? [];
    if (!is_array($incoming)) { throw new moodle_exception('invalidparameter'); }
    $upload = $incoming;
    if ($upload && !in_array($action, ['create','save'], true)) { throw new moodle_exception('invalidparameter'); }
    $uploadattempted = $upload && ($upload['error'] ?? null) !== UPLOAD_ERR_NO_FILE;
    try {
        $fileinfo = \local_kbacompany\local\document_upload::inspect($upload);
        $values['_file_digest'] = $fileinfo ? array_diff_key($fileinfo, ['tmp'=>true]) : null;
        if (!empty($form['uploadpending']) && !$fileinfo) { $errors['documentfile'] = ui::tr('dcf_reselect'); }
    } catch (moodle_exception $e) {
        $values['_file_digest'] = ['invalid'=>true];
        $errors['documentfile'] = $e->getMessage();
    }
    if (isset(documents::TYPE_LEVELS[$values['doctype']]) && $values['doctype'] !== 'other') {
        $values['doclevel'] = documents::TYPE_LEVELS[$values['doctype']];
    }
    $digest = forms::digest($values);
    $receipt = forms::replay($form,$digest);
    if ($receipt !== null) {
        // Company permission was rechecked; redirect only, without returning data from a possibly retired revision.
        redirect(ui::url($company,$receipt),ui::tr('dcu_already_saved'));
    }
}
if (in_array($view,['create','edit','source'],true) && !$manager) {
    throw new required_capability_exception($context,'local/kbacompany:manageorganization','nopermissions','');
}
if ($document !== '') {
    $model = documents::detail($company,$actor,$document,$revision);
    $revision = $model['selected']['revision'];
}
if (in_array($view,['detail','edit','action'],true) && !$model) { throw new moodle_exception('document_reference','local_kbacompany'); }
if ($method !== 'POST' && $view === 'edit' && !$model['can_edit']) { throw new moodle_exception('document_immutable','local_kbacompany'); }
if ($view === 'source') {
    if ($process === '') { throw new moodle_exception('document_reference','local_kbacompany'); }
    $preview = documents::source_preview($company,$actor,$process);
    $model = ['selected'=>['uuid'=>'','revision'=>0,'status'=>'draft','code'=>$preview['code'],'title'=>$preview['title']],
        'can_manage'=>true,'actions'=>['createstp'],'edit_token'=>$preview['hash']];
    $action = 'createstp';
    $proof['processuuid'] = $process;
}
if (in_array($view,['action','source'],true) && !in_array($action,$model['actions'],true)) {
    throw new moodle_exception('document_state','local_kbacompany');
}
if ($method === 'POST') {
    if ($action === 'create' || $action === 'save') {
        $errors += documents::form_errors($company,$actor,$values,!$model || $model['selected']['source_kind'] === 'manual',$document,$revision);
    } else {
        if (!$values['confirmed']) { $errors['_form'] = ui::tr('dcu_confirm_required'); }
        $required = [];
        if (in_array($action,['cancel','newrevision'],true)) { $required[] = 'reason'; }
        if ($action === 'reviewreject') { $required[] = 'comment'; }
        if ($action === 'createrecord') { $required = ['title','payload']; }
        if ($action === 'addreviewer' && $values['reviewerid'] <= 0) { $required[] = 'reviewerid'; }
        if ($action === 'requireack' && $values['userid'] <= 0) { $required[] = 'userid'; }
        foreach ($required as $field) { if (trim((string)$values[$field]) === '' || $values[$field] === 0) { $errors[$field] = ui::tr('dcu_required'); } }
        foreach (['title'=>255,'reason'=>4000,'comment'=>4000,'changereason'=>4000,'payload'=>100000] as $field=>$max) {
            if (core_text::strlen((string)$values[$field]) > $max) { $errors[$field] = ui::tr('dcu_toolong',$max); }
        }
        if ($action === 'publish') {
            try { $effective = documents::effective_date($values['effectivedate']); }
            catch (moodle_exception $e) { $errors['effectivedate'] = $e->getMessage(); }
        }
    }
    if (!$errors) {
        $expected = $form['expected'];
        $destination = $document !== '' ? ['document'=>$document] : [];
        try {
            switch ($action) {
                case 'create': $values['upload'] = $upload; $saved = documents::create_manual($company,$actor,$values); $destination = ['document'=>$saved]; break;
                case 'save': $values['upload'] = $upload; $values['expected'] = $expected; documents::save($company,$actor,$document,$revision,$values); break;
                case 'createstp': $saved = documents::create_from_stp($company,$actor,$process,$values['changereason'],$expected); $destination = ['document'=>$saved]; break;
                case 'addreviewer': documents::add_reviewer($company,$actor,$document,$revision,$values['reviewerid'],$expected); $destination['tab'] = 'reviews'; break;
                case 'submitreview': documents::submit_review($company,$actor,$document,$revision,$expected); break;
                case 'reviewapprove': case 'reviewreject':
                    documents::review_decision($company,$actor,$document,$revision,$action === 'reviewapprove' ? 'approved' : 'rejected',$values['comment'],$expected);
                    // Review access ends when the revision leaves review. Never broaden it to show a success screen.
                    $destination = $manager ? ['document'=>$document,'tab'=>'reviews'] : []; break;
                case 'returndraft': documents::return_to_draft($company,$actor,$document,$revision,$expected); break;
                case 'approve': documents::approve($company,$actor,$document,$revision,$values['comment'],$expected); break;
                case 'publish': documents::publish($company,$actor,$document,$revision,$effective,$expected); break;
                case 'activate': documents::activate($company,$actor,$document,$revision,$expected); break;
                case 'newrevision': $newrev = documents::new_revision($company,$actor,$document,$revision,$values['reason'],$expected); $destination['rev'] = $newrev; break;
                case 'cancel': documents::cancel($company,$actor,$document,$revision,$values['reason'],$expected); break;
                case 'archive': documents::archive($company,$actor,$document,$revision,$expected); break;
                case 'requireack': documents::require_acknowledgement($company,$actor,$document,$revision,$values['userid'],$expected); $destination['tab'] = 'acknowledgements'; $destination['rev']=$revision; break;
                case 'acknowledge': documents::acknowledge($company,$actor,$document,$revision,$expected); $destination['tab'] = 'acknowledgements'; $destination['rev']=$revision; break;
                case 'createrecord': documents::create_record($company,$actor,$document,$revision,$values['title'],$values['payload'],'','',$expected); $destination['tab'] = 'records'; $destination['rev']=$revision; break;
            }
        } catch (moodle_exception $e) {
            $key = strpos($e->errorcode, 'dcf_') === 0 || $e->errorcode === 'document_fileconfirmation' ? 'documentfile' : '_form';
            $errors[$key] = $e->getMessage();
        }
        if (!$errors) {
            forms::complete($requestkey,$digest,$destination);
            redirect(ui::url($company,$destination),ui::tr('document_saved'));
        }
    }
    if ($errors && ($uploadattempted || !empty($form['uploadpending']))) {
        forms::upload_pending($requestkey);
        $errors['_file_retry'] = ui::tr('dcf_reselect');
    }
}
$options = ['positions'=>[],'members'=>[]];
if (in_array($view,['list','create','edit'],true) || ($view === 'action' && in_array($action,['addreviewer','requireack'],true))) {
    $options = documents::form_options($company,$actor,$view === 'action');
}
if ($method !== 'POST' && in_array($view,['create','edit','action','source'],true)) {
    $proof = forms::issue($company,$actor,$document,$revision,$view === 'create' ? 'create' : ($view === 'edit' ? 'save' : $action),$model['edit_token'] ?? '',$process);
    if ($view === 'source') { $proof['processuuid'] = $process; }
    $values = ['doctype'=>'procedure','doclevel'=>2];
    if ($view === 'edit') {
        $doc = $model['selected'];
        $values = ['title'=>$doc['title'],'doctype'=>$doc['type'],'doclevel'=>$doc['level'],
            'ownerpositionuuid'=>$doc['owner_position_uuid'],'content'=>$doc['content'],'changereason'=>$doc['change_reason']];
    }
}
$PAGE->set_context($context);
$PAGE->set_url(ui::url($company,$document !== '' ? ['document'=>$document] : []));
$PAGE->set_title(ui::tr('document_title'));
$PAGE->set_heading(ui::tr('document_title'));
$PAGE->requires->css(new moodle_url('/local/kbacompany/styles.css'));
$shell = bms_page_shell_service::begin($company,$actor,'documents',ui::tr('document_title'));
echo html_writer::start_div('bms-dcu');
echo bms_workspace_ux_service::governance_nav($company,'documents');
if ($view === 'list') {
    $filters = [];
    foreach (['q','status','doctype','owner'] as $field) { $filters[$field] = optional_param($field,'',PARAM_RAW_TRIMMED); }
    $model = documents::browse($company,$actor,$filters,optional_param('page',0,PARAM_INT));
    echo ui::listing($company,$model,$filters,$options);
} else if ($view === 'create' || $view === 'edit') {
    echo ui::form($company,$options,$values,$errors,$proof,$view === 'edit' ? $model : null);
} else if ($view === 'action' || $view === 'source') {
    echo ui::action_form($company,$model,$action,$options,$values,$errors,$proof);
    if ($view === 'source') { echo ui::source_preview($preview); }
} else {
    if (!in_array($tab,ui::tabs($model),true)) { throw new moodle_exception('invalidparameter'); }
    $panel = $tab === 'overview' ? null : documents::panel($company,$actor,$document,$revision,$tab,optional_param('page',0,PARAM_INT));
    $discussionurl = new moodle_url('/local/kbacompany/communication_center.php', [
        'uuid'=>$company, 'tab'=>'discussions', 'objectkind'=>'document', 'objectuuid'=>$document,
        'objectrevision'=>$revision,
    ]);
    echo html_writer::div(html_writer::link($discussionurl, get_string('communication_history','local_kbacompany'),
        ['class'=>'btn btn-outline-primary btn-sm']), 'mb-3');
    echo ui::detail($company,$model,$tab,$panel);
}
echo html_writer::end_div();
bms_page_shell_service::finish($shell);
