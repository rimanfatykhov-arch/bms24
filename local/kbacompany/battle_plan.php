<?php
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\battle_plan_service;
use local_kbacompany\local\battle_plan_ui_service as ui;
use local_kbacompany\local\task_service;
use local_kbacompany\local\company_context_service;
use local_kbacompany\local\recurring_task_library_service;
use local_kbacompany\local\battle_plan_template_service;
use local_kbacompany\local\battle_plan_indicator_service;
use local_kbacompany\local\indicator_registry_service;
use local_kbacompany\local\indicator_condition_execution_service;
use local_kbacompany\local\bms_page_shell_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany',$context);
$uuid=required_param('uuid',PARAM_RAW_TRIMMED); $actor=(int)$USER->id;
company_context_service::context($uuid,$actor);
$method=$_SERVER['REQUEST_METHOD']??'GET';
if (!in_array($method,['GET','HEAD','POST'],true)) { http_response_code(405); throw new moodle_exception('battleplan_post','local_kbacompany'); }
$planuuid=optional_param('plan','',PARAM_RAW_TRIMMED);
$view=optional_param('view',$planuuid!==''?'detail':'list',PARAM_ALPHA);
$scope=optional_param('scope','all',PARAM_ALPHA);
if (in_array($view,['all','watching'],true)) { $scope=$view; $view=$planuuid!==''?'detail':'list'; }
if (!in_array($scope,['all','my','review','watching'],true)) { throw new moodle_exception('invalidparameter'); }
if (!in_array($view,['list','create','detail','edit','fill','action','metrics'],true)) { throw new moodle_exception('invalidparameter'); }
$tab=optional_param('tab','overview',PARAM_ALPHA);
if (!in_array($tab,['overview','tasks','indicators','review','history'],true)) { throw new moodle_exception('invalidparameter'); }
$templatekey=optional_param('template','',PARAM_ALPHANUMEXT);
if ($templatekey!=='' && $method!=='POST') { $view='fill'; }
$targetuser=optional_param($method==='POST'?'userid':'user',0,PARAM_INT);
$positionuuid=optional_param($method==='POST'?'positionuuid':'position','',PARAM_RAW_TRIMMED);
$week=optional_param('week','',PARAM_RAW_TRIMMED);
$action=optional_param($method==='POST'?'action':'op','',PARAM_ALPHA);
$itemuuid=optional_param($method==='POST'?'itemuuid':'item','',PARAM_RAW_TRIMMED);
$tasksearch=optional_param('taskq','',PARAM_TEXT);
$section=optional_param('section','',PARAM_ALPHA);
if ($section!=='' && !in_array($section, task_service::STREAMS, true)) { throw new moodle_exception('invalidparameter'); }
$fillflow=optional_param('flow','new',PARAM_ALPHA);
if ($method!=='POST' && optional_param('action','',PARAM_ALPHA)!=='') { throw new moodle_exception('battleplan_post','local_kbacompany'); }
$commands=['createplan','savesettings','observer','designrefs','addtask','addprovider','editaddprovider','addadmin','addwork','addtemplate','manual','editmanual','remove',
    'syncrecurring','applyplantemplate','indicatorfact','indicatorplan','indicatoraction','indicatorlinktask','metricfact','metricstate','reviewplan','saveweek','rolloveropen','submit','selfapprove','close'];
$metricactions=['indicatorfact','indicatorplan','indicatoraction','indicatorlinktask','metricfact','metricstate'];
$fillactions=['syncrecurring','addtask','addprovider','addadmin','addwork','addtemplate','manual','applyplantemplate'];
$values=[]; $errors=[]; $error=''; $data=null;
if ($method==='POST') {
    require_sesskey();
    if (!in_array($action,$commands,true)) { throw new moodle_exception('invalidparameter'); }
    $view=$action==='createplan'?'create':(in_array($action,['savesettings','observer','designrefs','editaddprovider','editmanual'],true)?'edit':
        (in_array($action,$fillactions,true)?'fill':(in_array($action,$metricactions,true)?'metrics':
        (in_array($action,['reviewplan','saveweek','rolloveropen'],true)?'detail':'action'))));
    if ($action==='reviewplan') { $tab='review'; }
    foreach (['periodstart','ownerposition','plantemplateuuid','normuuid','expected','title','product','description','stream','priority','startdate','starttime','duedate','duetime',
            'taskref','sourceuuid','reviewcomment','decision','factdate','amount','reason','comment','condition','taskuuid','binduuid','statuuid','weekgoal','weekoutcome'] as $field) {
        $values[$field]=optional_param($field,'',PARAM_RAW);
    }
    foreach (['reviewerid','observerid','planminutes'] as $field) { $values[$field]=optional_param($field,0,PARAM_INT); }
    $values['confirmed']=optional_param('confirmed',false,PARAM_BOOL);
    $values['dayplan']=optional_param_array('dayplan',[],PARAM_RAW_TRIMMED);
    $values['reviewitems']=optional_param_array('reviewitems',[],PARAM_RAW_TRIMMED);
    $values['rolloveritems']=optional_param_array('rolloveritems',[],PARAM_RAW_TRIMMED);
    if (in_array($action,['submit','selfapprove','close','remove','reviewplan','applyplantemplate'],true) && !$values['confirmed']) {
        $errors['_form']=ui::tr('bpu_confirm_required');
    }
    if ($action==='createplan') {
        if ($planuuid!=='') { throw new moodle_exception('invalidparameter'); }
        if ($values['reviewerid']<=0) { $errors['reviewerid']=ui::tr('battleplan_selectreviewer'); }
        try { battle_plan_service::week_start($values['periodstart']); }
        catch (moodle_exception $e) { $errors['periodstart']=$e->getMessage(); }
    }
    if ($action==='manual' && $section!=='' && trim($values['stream'])!==$section) {
        $errors['_form']=ui::tr('bpu_section_mismatch');
    }
    if (in_array($action,['manual','editmanual','addtemplate'],true)) {
        foreach ($action==='manual'?['title','product','duedate']:
                ($action==='editmanual'?['title','product']:['product']) as $field) {
            if (trim($values[$field])==='') { $errors[$field]=ui::tr('bpu_required'); }
        }
        foreach (['startdate','duedate'] as $field) {
            if ($values[$field]==='') { continue; }
            try { battle_plan_service::week_start($values[$field]); } catch (moodle_exception $e) { $errors[$field]=$e->getMessage(); }
        }
        if ($values['planminutes']<0) { $errors['planminutes']=ui::tr('bpu_nonnegative'); }
    }
    if (in_array($action,['savesettings','remove','reviewplan','saveweek','rolloveropen','submit','selfapprove','close'],true) &&
            !preg_match('/^[a-f0-9]{64}$/D', $values['expected'])) {
        $errors['_form']=ui::tr('battleplan_staleform');
    }
    if ($action==='reviewplan' && $values['decision']==='changes' && trim($values['reviewcomment'])==='') { $errors['reviewcomment']=ui::tr('battleplan_reviewcomment'); }
}
if ($view==='list' && $planuuid!=='') { $view='detail'; }
if ($planuuid!=='') {
    $data=battle_plan_service::context($uuid,$actor,$planuuid,0,'','',[
        'load_plans'=>false,'load_sources'=>in_array($view,['fill','edit'],true),'task_search'=>$tasksearch]);
    $data['actor']=$actor; $data['task_search']=$tasksearch;
    $week=$data['selected']['period_start'];
}
if (in_array($view,['detail','edit','fill','action','metrics'],true) && !$data) { throw new moodle_exception('battleplan_reference','local_kbacompany'); }
if (in_array($view,['edit','fill'],true) && (!$data['selected_can_edit'] || $data['selected']['status']==='closed' ||
        ($view==='fill' && !in_array($data['selected']['status'],['draft','active'],true)))) {
    throw new moodle_exception('battleplan_permission','local_kbacompany');
}
if ($view==='fill' && $data['selected']['status']==='active' &&
        ($templatekey!=='' || $fillflow==='existing' || ($method==='POST' && $action!=='manual'))) {
    throw new moodle_exception('battleplan_permission','local_kbacompany');
}
// A reviewer retains the existing legacy quota override, but not the owner's fact-entry permission.
if ($view==='metrics' && ($data['selected']['status']==='closed' ||
        (!$data['selected_can_edit'] && !($data['selected']['reviewer_id']===$actor && $data['selected']['norm_uuid']!=='') &&
         !($action === 'indicatorplan' && indicator_registry_service::can_manage($uuid, $actor))))) {
    throw new moodle_exception('battleplan_permission','local_kbacompany');
}
if ($view==='action') {
    if (!in_array($action,['remove','submit','selfapprove','close'],true) || !$data['selected_can_edit']) { throw new moodle_exception('battleplan_permission','local_kbacompany'); }
    $p=$data['selected'];
    if (($action==='close' && $p['status']!=='active') || ($action!=='close' && $p['status']!=='draft')) { throw new moodle_exception('battleplan_state','local_kbacompany'); }
    if ($action==='selfapprove' && ($p['user_id']!==$actor || $p['reviewer_id']!==$actor)) { throw new moodle_exception('battleplan_permission','local_kbacompany'); }
    if ($action==='remove' && !in_array($itemuuid,array_column($data['items'],'uuid'),true)) { throw new moodle_exception('battleplan_reference','local_kbacompany'); }
}
if ($view==='create') {
    $createcontext=battle_plan_service::form_context($uuid,$actor,$targetuser,$positionuuid);
    if ($method==='POST' && $createcontext['position_uuid']==='') { $errors['positionuuid']=ui::tr('battleplan_positionerror'); }
}
if ($method==='POST' && !$errors) {
    try {
        if ($action === 'createplan') {
            $targetuser = required_param('userid', PARAM_INT);
            $positionuuid = required_param('positionuuid', PARAM_RAW_TRIMMED);
            $week = required_param('periodstart', PARAM_RAW_TRIMMED);
            $planuuid = battle_plan_service::create_plan($uuid, (int)$USER->id, $targetuser, $positionuuid, $week,
                required_param('reviewerid', PARAM_INT), optional_param('observerid', 0, PARAM_INT),
                optional_param('plantemplateuuid', '', PARAM_RAW_TRIMMED), optional_param('normuuid', '', PARAM_RAW_TRIMMED));
        } else if ($action === 'savesettings') {
            $settings = [
                'reviewerid'=>optional_param('reviewerid',$data['selected']['reviewer_id'],PARAM_INT),
                'observerid'=>optional_param('observerid',0,PARAM_INT),
            ];
            if ($data['selected']['status'] === 'draft') {
                $settings += [
                    'periodstart'=>required_param('periodstart',PARAM_RAW_TRIMMED),
                    'ownerposition'=>optional_param('ownerposition','',PARAM_RAW_TRIMMED),
                    'plantemplateuuid'=>optional_param('plantemplateuuid','',PARAM_RAW_TRIMMED),
                    'normuuid'=>optional_param('normuuid','',PARAM_RAW_TRIMMED),
                ];
            }
            battle_plan_service::save_settings($uuid,(int)$USER->id,$planuuid,required_param('expected',PARAM_RAW_TRIMMED),$settings);
        } else if ($action === 'observer') {
            $planuuid = required_param('plan', PARAM_RAW_TRIMMED);
            battle_plan_service::set_observer($uuid, (int)$USER->id, $planuuid, optional_param('observerid', 0, PARAM_INT));
        } else if ($action === 'designrefs') {
            $planuuid = required_param('plan', PARAM_RAW_TRIMMED);
            battle_plan_service::set_design_references($uuid, (int)$USER->id, $planuuid,
                optional_param('plantemplateuuid', '', PARAM_RAW_TRIMMED), optional_param('normuuid', '', PARAM_RAW_TRIMMED));
        } else if ($action === 'syncrecurring') {
            $planuuid = required_param('plan', PARAM_RAW_TRIMMED);
            battle_plan_service::sync_recurring_week($uuid, (int)$USER->id, $planuuid);
        } else if ($action === 'addtask') {
            $planuuid = required_param('plan', PARAM_RAW_TRIMMED);
            battle_plan_service::add_task($uuid, (int)$USER->id, $planuuid, required_param('taskuuid', PARAM_RAW_TRIMMED));
        } else if (in_array($action, ['addprovider','editaddprovider'], true)) {
            $planuuid = required_param('plan', PARAM_RAW_TRIMMED);
            $ref = required_param('taskref', PARAM_RAW_TRIMMED);
            [$provider, $externalid] = array_pad(explode('::', $ref, 2), 2, '');
            battle_plan_service::add_external_task($uuid, (int)$USER->id, $planuuid, $provider, $externalid);
        } else if ($action === 'addadmin') {
            $planuuid = required_param('plan', PARAM_RAW_TRIMMED);
            battle_plan_service::add_from_admin_scale($uuid, (int)$USER->id, $planuuid, required_param('sourceuuid', PARAM_RAW_TRIMMED));
        } else if ($action === 'addwork') {
            $planuuid = required_param('plan', PARAM_RAW_TRIMMED);
            battle_plan_service::add_from_position_work($uuid, (int)$USER->id, $planuuid, required_param('sourceuuid', PARAM_RAW_TRIMMED));
        } else if ($action === 'addtemplate') {
            $planuuid = required_param('plan', PARAM_RAW_TRIMMED);
            $templatekey = required_param('template', PARAM_ALPHANUMEXT);
            battle_plan_service::add_from_template($uuid, (int)$USER->id, $planuuid, $templatekey, [
                'product' => required_param('product', PARAM_RAW),
                'description' => optional_param('description', '', PARAM_RAW),
                'priority' => required_param('priority', PARAM_ALPHA),
                'stream' => required_param('stream', PARAM_ALPHA),
                'startdate' => optional_param('startdate', '', PARAM_RAW_TRIMMED),
                'duedate' => optional_param('duedate', '', PARAM_RAW_TRIMMED),
                'planminutes' => optional_param('planminutes', 0, PARAM_INT),
            ]);
            $templatekey = '';
        } else if (in_array($action, ['manual','editmanual'], true)) {
            $planuuid = required_param('plan', PARAM_RAW_TRIMMED);
            // Keep quick-create semantically identical to Task Center create: canonical task + plan link + optional file.
            $tx = $DB->start_delegated_transaction();
            $createdtask = battle_plan_service::create_manual_task($uuid, (int)$USER->id, $planuuid, [
                'title' => required_param('title', PARAM_TEXT), 'description' => optional_param('description','',PARAM_RAW),
                'product' => required_param('product', PARAM_RAW), 'reviewerid' => optional_param('reviewerid',0,PARAM_INT),
                'observerid' => optional_param('observerid',0,PARAM_INT),
                'priority' => required_param('priority', PARAM_ALPHA), 'stream' => required_param('stream', PARAM_ALPHA),
                'startdate' => optional_param('startdate','',PARAM_RAW_TRIMMED), 'starttime' => optional_param('starttime','',PARAM_RAW_TRIMMED),
                'duedate' => optional_param('duedate','',PARAM_RAW_TRIMMED), 'duetime' => optional_param('duetime','',PARAM_RAW_TRIMMED),
                'planminutes' => optional_param('planminutes',0,PARAM_INT), 'parentuuid' => '',
            ]);
            if (!empty($_FILES['attachment']) && (int)($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                task_service::add_attachment($uuid, (int)$USER->id, $createdtask, $_FILES['attachment']);
            }
            $tx->allow_commit();
        } else if ($action === 'remove') {
            $planuuid = required_param('plan', PARAM_RAW_TRIMMED);
            battle_plan_service::remove_task($uuid, (int)$USER->id, $planuuid, required_param('itemuuid', PARAM_RAW_TRIMMED), required_param('expected', PARAM_RAW_TRIMMED));
        } else if ($action === 'applyplantemplate') {
            $planuuid = required_param('plan', PARAM_RAW_TRIMMED);
            battle_plan_service::apply_pinned_template($uuid, (int)$USER->id, $planuuid);
        } else if ($action === 'indicatorfact') {
            $planuuid = required_param('plan', PARAM_RAW_TRIMMED);
            battle_plan_indicator_service::record_fact($uuid, (int)$USER->id, $planuuid,
                required_param('binduuid', PARAM_RAW_TRIMMED), required_param('amount', PARAM_RAW_TRIMMED),
                optional_param('reason', '', PARAM_TEXT), optional_param('factdate', '', PARAM_RAW_TRIMMED));
        } else if ($action === 'indicatorplan') {
            $planuuid = required_param('plan', PARAM_RAW_TRIMMED);
            battle_plan_indicator_service::record_plan($uuid, (int)$USER->id, $planuuid,
                required_param('binduuid', PARAM_RAW_TRIMMED),
                optional_param_array('dayplan', [], PARAM_RAW_TRIMMED),
                optional_param('reason', '', PARAM_TEXT));
        } else if ($action === 'indicatoraction') {
            $planuuid = required_param('plan', PARAM_RAW_TRIMMED);
            indicator_condition_execution_service::confirm_from_battle_plan($uuid, (int)$USER->id, $planuuid,
                required_param('binduuid', PARAM_RAW_TRIMMED), required_param('condition', PARAM_ALPHA),
                optional_param('comment', '', PARAM_TEXT), [
                    'title' => required_param('title', PARAM_TEXT),
                    'description' => optional_param('description', '', PARAM_RAW),
                    'product' => required_param('product', PARAM_RAW),
                    'priority' => required_param('priority', PARAM_ALPHA),
                    'duedate' => optional_param('duedate', '', PARAM_RAW_TRIMMED),
                    'planminutes' => optional_param('planminutes', 0, PARAM_INT),
                ]);
        } else if ($action === 'indicatorlinktask') {
            $planuuid = required_param('plan', PARAM_RAW_TRIMMED);
            battle_plan_service::add_task($uuid, (int)$USER->id, $planuuid,
                required_param('taskuuid', PARAM_RAW_TRIMMED));
        } else if ($action === 'metricfact') {
            $planuuid = required_param('plan', PARAM_RAW_TRIMMED);
            battle_plan_template_service::record_fact($uuid, (int)$USER->id, $planuuid,
                required_param('statuuid', PARAM_RAW_TRIMMED), required_param('amount', PARAM_RAW_TRIMMED),
                optional_param('reason', '', PARAM_TEXT));
        } else if ($action === 'metricstate') {
            $planuuid = required_param('plan', PARAM_RAW_TRIMMED);
            battle_plan_template_service::set_condition_override($uuid, (int)$USER->id, $planuuid,
                required_param('statuuid', PARAM_RAW_TRIMMED), optional_param('condition', '', PARAM_ALPHA),
                optional_param('comment', '', PARAM_TEXT));
        } else if ($action === 'saveweek') {
            $planuuid = required_param('plan', PARAM_RAW_TRIMMED);
            battle_plan_service::save_week_workspace($uuid, (int)$USER->id, $planuuid,
                required_param('expected', PARAM_RAW_TRIMMED), optional_param('weekgoal', '', PARAM_RAW),
                optional_param('weekoutcome', '', PARAM_RAW));
        } else if ($action === 'rolloveropen') {
            $planuuid = required_param('plan', PARAM_RAW_TRIMMED);
            battle_plan_service::rollover_open_tasks($uuid, (int)$USER->id, $planuuid,
                required_param('expected', PARAM_RAW_TRIMMED), optional_param_array('rolloveritems', [], PARAM_RAW_TRIMMED));
        } else if ($action === 'reviewplan') {
            $planuuid = required_param('plan', PARAM_RAW_TRIMMED);
            $items = optional_param_array('reviewitems', [], PARAM_RAW_TRIMMED);
            battle_plan_service::review($uuid, (int)$USER->id, $planuuid,
                required_param('decision', PARAM_ALPHA), optional_param('reviewcomment', '', PARAM_TEXT), $items, required_param('expected', PARAM_RAW_TRIMMED));
        } else if (in_array($action, ['submit','selfapprove','close'], true)) {
            $planuuid = required_param('plan', PARAM_RAW_TRIMMED);
            battle_plan_service::transition($uuid, (int)$USER->id, $planuuid, $action, required_param('expected', PARAM_RAW_TRIMMED));
        }

        $destination=['plan'=>$planuuid];
        if (in_array($action,['savesettings','editaddprovider','editmanual'],true)) { $destination['view']='edit'; }
        if (in_array($action,$fillactions,true) || $action==='remove') { $destination['tab']='tasks'; }
        if (in_array($action,$metricactions,true)) { $destination['tab']='indicators'; }
        if ($action==='reviewplan') { $destination['tab']='review'; }
        if (in_array($action,['saveweek','rolloveropen'],true)) { $destination['tab']='overview'; }
        $redirecturl=ui::url($uuid,$destination);
        if ($action==='manual' && in_array((string)$values['stream'], task_service::STREAMS, true)) {
            $redirecturl->set_anchor('stream-'.$values['stream']);
        }
        redirect($redirecturl,ui::tr('battleplan_saved'));
    } catch (moodle_exception $e) {
        if ($e->module!=='local_kbacompany') { throw $e; }
        $error=$e->getMessage();
        $fieldmap=['battleplan_reviewererror'=>'reviewerid','battleplan_observererror'=>'observerid','battleplan_employeeerror'=>'ownerposition','battleplan_positionerror'=>'ownerposition','battleplan_duplicate'=>'periodstart','battleplan_date'=>'periodstart',
            'battleplan_reviewcomment'=>'reviewcomment','battleplan_weektext'=>'weekgoal','taskcenter_product'=>'product'];
        if (isset($fieldmap[$e->errorcode])) { $errors[$fieldmap[$e->errorcode]]=$error; }
    }
}
$PAGE->set_context($context);
$PAGE->set_url(ui::url($uuid,['view'=>$view,'plan'=>$planuuid]));
$PAGE->set_title(ui::tr('battleplan_title')); $PAGE->set_heading(ui::tr('pluginname'));
$shell=bms_page_shell_service::begin($uuid,$actor,'battleplan',ui::tr('battleplan_title'));
echo html_writer::start_div('bms-bpu');
if ($error!=='') { echo html_writer::div(s($error),'alert alert-danger',['role'=>'alert']); }
if ($errors) { echo html_writer::div(s($errors['_form']??ui::tr('bpu_fix_errors')),'alert alert-danger',['role'=>'alert']); }
if ($view==='list') {
    $filters=['scope'=>$scope,'q'=>optional_param('q','',PARAM_TEXT),'status'=>optional_param('status','',PARAM_ALPHA),
        'week'=>$week,'user'=>$targetuser];
    try { $listing=battle_plan_service::listing($uuid,$actor,$filters,optional_param('page',0,PARAM_INT)); }
    catch (moodle_exception $e) { throw $e; }
    echo ui::listing($uuid,$listing,$filters);
} else if ($view==='create') {
    echo ui::create_form($uuid,$createcontext,$values+['periodstart'=>$week!==''?$week:gmdate('Y-m-d')],$errors);
} else if ($view==='edit') {
    echo ui::edit_form($uuid,$data,$values,$errors);
} else if ($view==='fill') {
    $selectedtemplate=$templatekey!==''?recurring_task_library_service::template($templatekey,$uuid):null;
    echo ui::fill($uuid,$data,$values,$errors,$action,$selectedtemplate,$fillflow,$section);
} else if ($view==='action') {
    echo ui::action($uuid,$data,$action,$itemuuid,$values);
} else {
    if ($view==='metrics') { $tab='indicators'; }
    echo ui::record_header($uuid,$data,$tab);
    if ($tab==='overview') { echo ui::overview($uuid,$data); }
    else if ($tab==='tasks') { echo ui::tasks($uuid,$data); }
    else if ($tab==='review') { echo ui::review($uuid,$data,$values,$errors); }
    else if ($tab==='history') { echo ui::history($data); }
    else if ($tab==='indicators') {
        $plan=$data['selected']; $metricsedit=$view==='metrics';
        // A coverage authorization never confers metric plan/fact write privileges.
        if (!empty($data['coverage_window'])) {
            echo html_writer::div(get_string('bpu_coverage_metric_boundary', 'local_kbacompany'),
                'alert alert-info');
        }

        $canwritefact = !empty($data['selected_can_edit']) && $plan['status'] !== 'closed';
        // Legacy quota overrides and manager actions retain the specialist mode.
        if ($plan['status'] !== 'closed' && ($data['selected_can_edit'] ||
                ($plan['reviewer_id'] === $actor && $plan['norm_uuid'] !== ''))) {
            echo html_writer::div(ui::link(ui::url($uuid,['plan'=>$planuuid,
                'view'=>$metricsedit?'detail':'metrics','tab'=>'indicators']),
                ui::tr($metricsedit?'bpu_back_indicators':'bpu_metric_advanced_actions')),
                'bms-bpu-metric-settings');
        }
        // Do not expose legacy edit widgets outside the specialist mode.
        if (!$metricsedit) { $data['selected_can_edit']=false; }
        $hidden=static function(string $op,string $plan='') use($uuid): string {
            return ui::hidden(['uuid'=>$uuid,'plan'=>$plan,'action'=>$op,'view'=>'metrics','sesskey'=>sesskey()]);
        };
        $input=static function(string $name,string $value='',string $type='text',bool $required=false): string {
            return html_writer::empty_tag('input',['name'=>$name,'value'=>$value,'type'=>$type,'class'=>'form-control','required'=>$required?'required':null]);
        };
        $textarea=static function(string $name,string $value='',bool $required=false): string {
            return html_writer::tag('textarea',s($value),['name'=>$name,'class'=>'form-control','rows'=>3,'required'=>$required?'required':null]);
        };
        // Reuse posted values only for the submitted indicator row, never for neighbouring forms.
        $metricvalue=static function(string $op,string $idfield,string $id,string $name,$default='') use ($action,$values) {
            return $action===$op && (string)($values[$idfield]??'')===$id && array_key_exists($name,$values)
                ? $values[$name] : $default;
        };
        $priorities=[]; foreach (task_service::PRIORITIES as $priority) { $priorities[$priority]=ui::tr('taskcenter_priority_'.$priority); }
        $streams=[]; foreach (task_service::STREAMS as $stream) { $streams[$stream]=ui::tr('taskcenter_stream_'.$stream); }
        echo html_writer::start_div('bms-bpu-indicators');
        require(__DIR__.'/templates/battle_plan_indicators.php');
        echo html_writer::end_div();
    }
}
echo html_writer::end_div();
if ($view==='fill') {
    // Do not display the large Battle Plan AI panel on task creation. Field-level
    // task suggestions reuse the same authorised AI bridge as the Task Centre.
    $fillflow=optional_param('flow','new',PARAM_ALPHA);
    if ($templatekey!=='' || $action==='manual' || $action==='editmanual' || $action==='addtemplate' ||
            (!in_array($action,['addprovider','addtask','addadmin','addwork'],true) && $fillflow!=='existing')) {
        \local_kbacompany\local\bms_ai_workspace_service::render($uuid, 'task', 'Задачи', true);
    }
} else {
    \local_kbacompany\local\bms_ai_workspace_service::render($uuid, 'battleplan', 'Боевой план');
}
bms_page_shell_service::finish($shell);
