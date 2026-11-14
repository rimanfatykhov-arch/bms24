<?php
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\task_service;
use local_kbacompany\local\task_ui_service as ui;
use local_kbacompany\local\company_context_service;
use local_kbacompany\local\battle_plan_service;
use local_kbacompany\local\task_provider_service;
use local_kbacompany\local\task_list_preferences_service as taskprefs;
use local_kbacompany\local\communication_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$actor = (int)$USER->id;
company_context_service::context($uuid, $actor);
$manager = task_service::can_manage($uuid, $actor);
$selected = optional_param('task', '', PARAM_RAW_TRIMMED);
$view = optional_param('view', $selected !== '' ? 'detail' : 'list', PARAM_ALPHA);
$display = optional_param('display', 'list', PARAM_ALPHA);
if (in_array($view, ['kanban','analytics','deadlines','manager'], true)) { $display=$view; $view=$selected!==''?'detail':'list'; }
if ($selected!=='' && $view==='list') { $view='detail'; }
if (isset($_GET['form']) && $selected==='' && $view==='list') { $view='create'; }
if (!in_array($view,['list','detail','create','edit','action','sources','sourceconfig','relations','dutypositions','linksubtask','searchsubtasks'],true)) { $view=$selected!==''?'detail':'list'; }
if (!in_array($display,['list','kanban','analytics','deadlines','manager'],true)) { $display='list'; }
// Manager mode is server-gated; it never widens a non-manager's company visibility.
if ($display === 'manager' && !$manager) { $display = 'list'; }
$scope = task_service::normalise_scope(optional_param('scope','roles',PARAM_ALPHA),$manager);
// Executive board must always represent the company-wide permitted projection.
if ($display === 'manager') { $scope = 'all'; }
$tab = optional_param('tab','overview',PARAM_ALPHA);
if (!in_array($tab,['overview','checklist','result','files','discussion','history'],true)) { $tab='overview'; }
$operation = optional_param('operation','',PARAM_ALPHA);
$action = optional_param('action','',PARAM_ALPHA);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$discussionajax = $method === 'POST' && $action === 'discussionpost' && optional_param('discussionajax', 0, PARAM_BOOL);
$discussionreply = static function(bool $ok, string $requestkey, string $url = '', string $error = ''): void {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    if (!$ok) { http_response_code(400); }
    echo json_encode(['contract'=>'bms_task_discussion_v1', 'ok'=>$ok, 'requestkey'=>$requestkey,
        'url'=>$url, 'error'=>$error], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    exit;
};
if (!in_array($method,['GET','HEAD','POST'],true)) { throw new moodle_exception('taskcenter_post','local_kbacompany'); }
if ($view === 'dutypositions') {
    if ($method !== 'GET' && $method !== 'HEAD') {
        throw new moodle_exception('taskcenter_post', 'local_kbacompany');
    }
    $options = task_service::duty_position_options($uuid, $actor,
        optional_param('assigneeid', 0, PARAM_INT));
    $items = [];
    foreach ($options as $key => $label) { $items[] = ['value'=>(string)$key, 'label'=>(string)$label]; }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    echo json_encode(['options'=>$items], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS);
    exit;
}
// Read-only live lookup, reused by the existing link-subtask POST action.
// No task state changes or parent links are performed by this endpoint.
if ($view === 'searchsubtasks') {
    if ($method !== 'GET' && $method !== 'HEAD') {
        throw new moodle_exception('taskcenter_post', 'local_kbacompany');
    }
    if ($selected === '') {
        throw new moodle_exception('taskcenter_reference', 'local_kbacompany');
    }
    $query = core_text::substr(trim(optional_param('q', '', PARAM_RAW_TRIMMED)), 0, 160);
    $items = [];
    if (core_text::strlen($query) >= 2) {
        // The service enforces company membership, parent edit rights, and
        // visibility/edit eligibility of each candidate before returning a row.
        $items = task_service::subtask_candidates($uuid, $actor, $selected, $query, 21);
    }
    $more = count($items) > 20;
    $items = array_slice($items, 0, 20);
    $options = [];
    foreach ($items as $item) {
        $options[] = [
            'value' => $item['uuid'] . '|' . $item['revision'],
            'title' => $item['title'],
            'due' => $item['duedate'],
            'reference' => core_text::substr($item['uuid'], 0, 8),
        ];
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    echo json_encode(['options' => $options, 'more' => $more],
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS);
    exit;
}
if ($view === 'relations') {
    if ($method !== 'GET' && $method !== 'HEAD') { throw new moodle_exception('taskcenter_post', 'local_kbacompany'); }
    $options = task_service::relation_options($uuid, $actor, optional_param('q', '', PARAM_RAW_TRIMMED), $selected);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    echo json_encode(['options' => $options], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS);
    exit;
}
if ($view==='sourceconfig' && !$manager) { throw new moodle_exception('taskprovider_permission','local_kbacompany'); }
$filterplans = battle_plan_service::task_filter_plan_options($uuid, $actor);
$requestedplan = optional_param('battleplan','',PARAM_RAW_TRIMMED);
$filterplan = ($requestedplan !== '' && isset($filterplans[$requestedplan])) ? $requestedplan : '';
$signal = optional_param('signal', '', PARAM_ALPHA);
if (!in_array($signal, ['', 'open', 'done', 'overdue', 'review', 'returned', 'soon', 'undated', 'subtaskoverdue'], true)) { $signal = ''; }
// A Task List signal is a drill-down, not an independent dashboard dimension.
// Analytics always starts from the shared scope and explicit filter fields.
if (in_array($display, ['analytics','manager'], true)) { $signal = ''; }
$filters=['scope'=>$scope,'q'=>optional_param('q','',PARAM_RAW_TRIMMED),'status'=>optional_param('status','',PARAM_ALPHA),
    'stream'=>optional_param('stream','',PARAM_ALPHA),'sourcekind'=>optional_param('sourcekind','',PARAM_ALPHA),
    'battleplan'=>$filterplan,'signal'=>$signal,
    'assignee'=>max(0, optional_param('assignee', 0, PARAM_INT)),
    'project'=>optional_param('project', '', PARAM_RAW_TRIMMED)];
// Due group is a read-only filter, only meaningful for the dedicated Deadlines display.
if ($display === 'deadlines') {
    $filters['duegroup'] = task_service::normalise_duegroup(optional_param('duegroup', '', PARAM_ALPHA));
}
// Per-user/company defaults apply only to the list; explicit query sort remains bookmarkable.
$listprefs = taskprefs::current($uuid, $actor);
$filters['sort'] = taskprefs::sort(optional_param('sort', $listprefs['sort'], PARAM_ALPHA));
$filters['dir'] = taskprefs::direction(optional_param('dir', $listprefs['dir'], PARAM_ALPHA));
$errors=[]; $error=''; $values=[]; $requestkey=bin2hex(random_bytes(16));
$model = null;
if ($selected!=='' || in_array($view,['create','edit'],true) || in_array($action,['create','save'],true)) {
    $model=task_service::context($uuid,$actor,$selected,$scope,['recordonly'=>true,'safesources'=>true,
        'noformoptions'=>!in_array($view,['create','edit'],true) && !in_array($action,['create','save'],true),
        'nodiscussion'=>!in_array($tab,['overview','discussion'],true)]);
    $model['creation_plans']=$selected===''?battle_plan_service::manual_task_plan_options($uuid,$actor):[];
    $model['plans']=$selected!==''?battle_plan_service::task_plan_context($uuid,$actor,$selected):['eligible'=>[],'included'=>[]];
    $model['parent']=!empty($model['selected']['parent_uuid'])?task_service::readable_projection($uuid,$actor,$model['selected']['parent_uuid']):null;
}
$permissions=$selected!==''?task_service::record_actions($model['selected'],$actor,$manager,$uuid):[];
if ($selected !== '') {
    $model['can_add_subtasks'] = task_service::can_add_subtasks($uuid, $actor, $selected);
    if ($view === 'detail') {
        $model['subtasks'] = task_service::subtasks_page($uuid, $actor, $selected,
            optional_param('subpage', 0, PARAM_INT));
        // Server-side weekly rollup uses the full visible subtask set (never only the pager).
        $weekparam = optional_param('subweek', '', PARAM_RAW_TRIMMED);
        $weekday = $weekparam !== '' ? $weekparam : battle_plan_service::user_today();
        $weekstart = battle_plan_service::week_start($weekday);
        $model['subtask_week'] = task_service::subtask_week_plan_projection($uuid, $actor, $selected, $weekstart);
        $model['subtask_plan_memberships'] = battle_plan_service::task_plan_memberships($uuid, $actor,
            array_column($model['subtasks']['items'], 'uuid'));
    }
}
if ($selected !== '') { foreach ($model['plans']['included'] as $linkedplan) { if ($linkedplan['status'] !== 'draft') { $permissions['delete'] = false; } } }
if (in_array($view,['detail','edit','action','linksubtask'],true) && $selected==='') { throw new moodle_exception('taskcenter_reference','local_kbacompany'); }
if ($view==='edit' && empty($permissions['save'])) { throw new moodle_exception('taskcenter_permission','local_kbacompany'); }

$relation = static function(string $ref): array {
    if($ref===''){return ['parentuuid'=>'','contextkind'=>'','contextuuid'=>'','contextrevision'=>0];}
    $parts=explode('|',$ref);
    if(count($parts)!==3 || $parts[1]==='' || !ctype_digit($parts[2])) { throw new moodle_exception('taskcenter_context','local_kbacompany'); }
    if($parts[0]==='task' && (int)$parts[2]===0){return ['parentuuid'=>$parts[1],'contextkind'=>'','contextuuid'=>'','contextrevision'=>0];}
    if(!in_array($parts[0],task_service::CONTEXT_KINDS,true)||(int)$parts[2]<=0){throw new moodle_exception('taskcenter_context','local_kbacompany');}
    return ['parentuuid'=>'','contextkind'=>$parts[0],'contextuuid'=>$parts[1],'contextrevision'=>(int)$parts[2]];
};
$confirmops=['classifyduty','start','submit','accept','return','cancel','delete','observer','addbattleplan','attachmentdelete','resultattachmentdelete'];
$allowed=array_merge(['create','save','checkadd','checkset','checkedit','checkdelete','checkmove','linksubtask','discussionpost','attachmentadd','resultattachmentadd','bindsource','unbindsource'],$confirmops);
if($action!=='') {
    if($method!=='POST'){throw new moodle_exception('taskcenter_post','local_kbacompany');}
    require_sesskey();
    if(!in_array($action,$allowed,true)){throw new moodle_exception('taskcenter_invalid','local_kbacompany');}
    $requestkey=optional_param('requestkey','',PARAM_ALPHANUM);
    $values=['title'=>optional_param('title','',PARAM_TEXT),'description'=>optional_param('description','',PARAM_RAW),
        'product'=>optional_param('product','',PARAM_RAW),'assigneeid'=>optional_param('assigneeid',0,PARAM_INT),
        'reviewerid'=>optional_param('reviewerid',0,PARAM_INT),'observerid'=>optional_param('observerid',0,PARAM_INT),
        'priority'=>optional_param('priority','normal',PARAM_ALPHA),'stream'=>optional_param('stream','operate',PARAM_ALPHA),
        'startdate'=>optional_param('startdate','',PARAM_RAW_TRIMMED),'starttime'=>optional_param('starttime','',PARAM_RAW_TRIMMED),
        'duedate'=>optional_param('duedate','',PARAM_RAW_TRIMMED),'duetime'=>optional_param('duetime','',PARAM_RAW_TRIMMED),
        'planminutes'=>optional_param('planminutes',0,PARAM_INT),'relationref'=>optional_param('relationref','',PARAM_RAW_TRIMMED),
        'battleplanuuid'=>optional_param('battleplanuuid','',PARAM_RAW_TRIMMED),'revision'=>optional_param('revision',0,PARAM_INT),
        'result'=>optional_param('result','',PARAM_RAW),'factminutes'=>optional_param('factminutes',0,PARAM_INT),
        'reason'=>optional_param('reason','',PARAM_RAW),'plan'=>optional_param('plan','',PARAM_RAW_TRIMMED),
        'filename'=>optional_param('filename','',PARAM_FILE),'message'=>optional_param('message','',PARAM_RAW),
        'itemtext'=>optional_param('itemtext','',PARAM_RAW),'checkassigneeid'=>optional_param('checkassigneeid',0,PARAM_INT),
        'dutypositionuuid'=>optional_param('dutypositionuuid','',PARAM_RAW_TRIMMED),
        'dutyconfirmed'=>optional_param('dutyconfirmed',0,PARAM_BOOL),
        'childchoice'=>optional_param('childchoice','',PARAM_RAW_TRIMMED)];
    $tx=null;
    try {
        if(!preg_match('/^[a-f0-9]{32}$/D',$requestkey)){throw new moodle_exception('tui_expired_form','local_kbacompany');}
        // Bounded, session-local replay protection. Not durable exactly-once across session loss.
        $receiptkey=$uuid.':'.$actor.':'.$requestkey;
        $payload=$_POST;unset($payload['sesskey'],$payload['requestkey']);ksort($payload);
        foreach(['attachment','resultattachment','discussionattachment'] as $filekey){
            foreach(communication_service::normalise_uploads($_FILES[$filekey]??null) as $file){
                $tmp=(string)($file['tmp_name']??'');$payload['upload_'.$filekey][]=['name'=>$file['name']??'',
                    'sha256'=>is_uploaded_file($tmp)?hash_file('sha256',$tmp):'invalid'];
            }
        }
        if($discussionajax && optional_param('discussionfilecount',-1,PARAM_INT)!==
                count(communication_service::normalise_uploads($_FILES['discussionattachment']??null))){
            throw new moodle_exception('communication_upload','local_kbacompany');
        }
        $fingerprint=hash('sha256',json_encode($payload));
        $receipts=$SESSION->kbataskreceipts??[];
        foreach($receipts as $k=>$r){if($r['time']<time()-7200){unset($receipts[$k]);}}
        if(isset($receipts[$receiptkey])){
            $r=$receipts[$receiptkey];
            if($r['fingerprint']!==$fingerprint){throw new moodle_exception('tui_expired_form','local_kbacompany');}
            if($r['task']!==''&&!task_service::readable_projection($uuid,$actor,$r['task'])){throw new moodle_exception('taskcenter_reference','local_kbacompany');}
            if($discussionajax){$discussionreply(true,$requestkey,ui::url($uuid,['task'=>$r['task'],'view'=>$r['view'],'tab'=>$r['tab']])->out(false));}
            redirect(ui::url($uuid,['task'=>$r['task'],'view'=>$r['view'],'tab'=>$r['tab']]),ui::tr('taskcenter_saved'));
        }
        if($action!=='create'&&!in_array($action,['bindsource','unbindsource'],true)&&$selected===''){throw new moodle_exception('taskcenter_reference','local_kbacompany');}
        if(in_array($action,$confirmops,true)&&!optional_param('confirmed',false,PARAM_BOOL)){$errors['confirmed']=ui::tr('tui_confirm_required');throw new moodle_exception('tui_confirm_required','local_kbacompany');}
        if(array_key_exists($action,$permissions)&&!$permissions[$action]){throw new moodle_exception('taskcenter_permission','local_kbacompany');}
        if(in_array($action,['create','save'],true)){
            foreach(['title'=>255,'product'=>12000,'description'=>12000] as $field=>$max){
                if(($field!=='description'&&trim($values[$field])==='')||core_text::strlen($values[$field])>$max){$errors[$field]=ui::tr('tui_field_required');}}
            if(!isset($model['employees'][$values['assigneeid']])){$errors['assigneeid']=ui::tr('taskcenter_employee');}
            foreach(['reviewerid','observerid'] as $field){if($values[$field]>0&&!isset($model['employees'][$values[$field]])){$errors[$field]=ui::tr('taskcenter_employee');}}
            foreach(['startdate','duedate'] as $field){$v=$values[$field];$d=DateTimeImmutable::createFromFormat('!Y-m-d',$v,new DateTimeZone('UTC'));if($v!==''&&(!$d||$d->format('Y-m-d')!==$v)){$errors[$field]=ui::tr('taskcenter_date');}}
            foreach(['starttime','duetime'] as $field){$v=$values[$field];if($v!==''&&!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D',$v)){$errors[$field]=ui::tr('taskcenter_date');}}
            if($values['starttime']!==''&&$values['startdate']===''){$errors['starttime']=ui::tr('taskcenter_date');}
            if($values['duetime']!==''&&$values['duedate']===''){$errors['duetime']=ui::tr('taskcenter_date');}
            if($values['startdate']!==''&&$values['duedate']!==''){
                if($values['duedate']<$values['startdate']){$errors['duedate']=ui::tr('taskcenter_date');}
                if($values['duedate']===$values['startdate']&&$values['starttime']!==''&&$values['duetime']!==''&&$values['duetime']<$values['starttime']){$errors['duetime']=ui::tr('taskcenter_date');}
            }
            if($values['planminutes']<0||$values['planminutes']>525600){$errors['planminutes']=ui::tr('taskcenter_invalid');}
            if($errors){throw new moodle_exception('tui_check_fields','local_kbacompany');}
            $data=array_merge($values,$relation($values['relationref']));
            if($action==='create'&&$values['battleplanuuid']!==''){
                try {
                    battle_plan_service::validate_manual_task_plan_target($uuid,$actor,$values['battleplanuuid'],$values['assigneeid']);
                } catch (\moodle_exception $e) {
                    if ($e->errorcode === 'taskcenter_battleplan_assignee_mismatch') {
                        $errors['battleplanuuid']=ui::tr('taskcenter_battleplan_assignee_mismatch');
                        throw new moodle_exception('tui_check_fields','local_kbacompany');
                    }
                    throw $e;
                }
            }
            // Task + optional plan reference + attachment metadata succeed or roll back together.
            $tx=$DB->start_delegated_transaction();
            if($action==='create'){
                $selected=$values['battleplanuuid']!==''
                    ? battle_plan_service::create_manual_task($uuid,$actor,$values['battleplanuuid'],$data)
                    : task_service::create($uuid,$actor,$data);
            } else {
                task_service::save($uuid,$actor,$selected,$values['revision'],$data);
            }
            if(!empty($_FILES['attachment'])){task_service::add_attachment($uuid,$actor,$selected,$_FILES['attachment']);}
        }else if($action==='classifyduty'){
            if(empty($permissions['classify_duty'])){throw new moodle_exception('taskcenter_permission','local_kbacompany');}
            if(!optional_param('confirmed',false,PARAM_BOOL)){throw new moodle_exception('tui_confirm_required','local_kbacompany');}
            task_service::classify_duty($uuid,$actor,$selected,$values['revision'],$values['dutypositionuuid']);
        }else if(in_array($action,['start','submit','accept','return','cancel'],true)){
            if($action==='submit'&&(trim($values['result'])===''||core_text::strlen($values['result'])>12000)){$errors['result']=ui::tr('tui_field_required');throw new moodle_exception('tui_check_fields','local_kbacompany');}
            if(in_array($action,['return','cancel'],true)&&trim($values['reason'])===''){$errors['reason']=ui::tr('tui_field_required');throw new moodle_exception('tui_check_fields','local_kbacompany');}
            if($values['factminutes']<0||$values['factminutes']>525600){$errors['factminutes']=ui::tr('taskcenter_invalid');throw new moodle_exception('tui_check_fields','local_kbacompany');}
            $tx=$DB->start_delegated_transaction();
            task_service::transition($uuid,$actor,$selected,$values['revision'],$action,$values);
            if($action==='submit'&&!empty($_FILES['resultattachment'])){task_service::add_result_attachment($uuid,$actor,$selected,$_FILES['resultattachment']);}
            $tab=$action==='submit'?'result':'overview';
        }else if($action==='observer'){task_service::set_observer($uuid,$actor,$selected,$values['revision'],$values['observerid']);}
        else if($action==='addbattleplan'){battle_plan_service::add_task($uuid,$actor,$values['plan'],$selected);}
        else if($action==='delete'){task_service::delete_task($uuid,$actor,$selected,$values['revision']);$selected='';}
        else if($action==='attachmentadd'||$action==='resultattachmentadd'){
            $key=$action==='attachmentadd'?'attachment':'resultattachment';
            if(empty($_FILES[$key])||(int)($_FILES[$key]['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE){throw new moodle_exception('taskcenter_upload','local_kbacompany');}
            if($action==='attachmentadd'){task_service::add_attachment($uuid,$actor,$selected,$_FILES[$key]);$tab='files';}
            else{task_service::add_result_attachment($uuid,$actor,$selected,$_FILES[$key]);$tab='result';}
        }else if($action==='attachmentdelete'){task_service::delete_attachment($uuid,$actor,$selected,$values['filename']);$tab='files';}
        else if($action==='resultattachmentdelete'){task_service::delete_result_attachment($uuid,$actor,$selected,$values['filename']);$tab='result';}
        else if($action==='discussionpost'){task_service::post_discussion_message($uuid,$actor,$selected,optional_param('message','',PARAM_RAW),$_FILES['discussionattachment']??null);$tab='discussion';}
        else if($action==='checkadd'){task_service::add_checklist($uuid,$actor,$selected,required_param('itemtext',PARAM_RAW),optional_param('checkassigneeid',0,PARAM_INT));}
        else if($action==='checkset'){task_service::set_checklist_state($uuid,$actor,$selected,required_param('checkuuid',PARAM_RAW_TRIMMED),required_param('done',PARAM_BOOL));}
        else if($action==='checkedit'){task_service::update_checklist($uuid,$actor,$selected,
            required_param('checkuuid',PARAM_RAW_TRIMMED),required_param('itemtext',PARAM_RAW),
            optional_param('checkassigneeid',0,PARAM_INT));}
        else if($action==='checkdelete'){
            if(!optional_param('confirmed',false,PARAM_BOOL)){
                throw new moodle_exception('tui_confirm_required','local_kbacompany');
            }
            task_service::delete_checklist($uuid,$actor,$selected,required_param('checkuuid',PARAM_RAW_TRIMMED));
        }
        else if($action==='linksubtask') {
            $parts=explode('|',$values['childchoice']);
            if(count($parts)!==2 || !preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/Di',$parts[0]) || !ctype_digit($parts[1])) {
                throw new moodle_exception('taskcenter_subtask_invalid','local_kbacompany');
            }
            task_service::link_subtask($uuid,$actor,$selected,$parts[0],(int)$parts[1]);
            $tab='overview';
        }
        else if($action==='checkmove'){task_service::move_checklist($uuid,$actor,$selected,
            required_param('checkuuid',PARAM_RAW_TRIMMED),required_param('direction',PARAM_ALPHA));}
        else if($action==='bindsource'||$action==='unbindsource'){
            if(!$manager){throw new moodle_exception('taskprovider_permission','local_kbacompany');}
            if(!optional_param('confirmed',false,PARAM_BOOL)){throw new moodle_exception('tui_confirm_required','local_kbacompany');}
            $providerkey=required_param('providerkey',PARAM_ALPHANUMEXT);$sourcekey=required_param('sourcekey',PARAM_RAW_TRIMMED);
            if($action==='bindsource'){task_provider_service::bind_source($uuid,$actor,$providerkey,$sourcekey);}
            else{task_provider_service::unbind_source($uuid,$actor,$providerkey,$sourcekey);}
        }
        if($tx){$tx->allow_commit();$tx=null;}
        $nextview=in_array($action,['bindsource','unbindsource'],true)?'sourceconfig':($selected!==''?'detail':'list');
        $receipts[$receiptkey]=['fingerprint'=>$fingerprint,'task'=>$selected,'view'=>$nextview,'tab'=>$tab,'time'=>time()];
        $SESSION->kbataskreceipts=array_slice($receipts,-40,null,true);
        if($discussionajax){$discussionreply(true,$requestkey,ui::url($uuid,['task'=>$selected,'view'=>$nextview,'tab'=>$tab,'scope'=>$scope])->out(false));}
        redirect(ui::url($uuid,['task'=>$selected,'view'=>$nextview,'tab'=>$tab,'scope'=>$scope]),ui::tr('taskcenter_saved'));
    }catch(Throwable $e){
        if($tx){try{$tx->rollback($e);}catch(Throwable $rolled){$e=$rolled;}}
        if(!($e instanceof moodle_exception)){throw $e;}
        $error=$e->getMessage();
        if($discussionajax){$discussionreply(false,$requestkey,'',$error);}
        if($action==='create'){$view='create';$selected='';}
        else if($action==='save'){$view='edit';}
        else if($action==='linksubtask'){$view='linksubtask';}
        else if(in_array($action,$confirmops,true)){$view='action';$operation=$action;}
        else if(in_array($action,['bindsource','unbindsource'],true)){$view='sourceconfig';}
        else{$view='detail';}
        $fieldmap=['taskcenter_employee'=>'assigneeid','taskcenter_reviewererror'=>'reviewerid','taskcenter_parent'=>'relationref',
            'taskcenter_context'=>'relationref','taskcenter_date'=>'duedate','taskcenter_upload'=>'attachment','taskcenter_filesize'=>'attachment'];
        $fieldmap['coverage_task_invalid_position']='dutypositionuuid';
        $fieldmap['tui_confirm_required']='dutyconfirmed';
        if(isset($fieldmap[$e->errorcode])&&in_array($action,['create','save'],true)){$errors[$fieldmap[$e->errorcode]]=$error;}
        if(in_array($action,['checkset','checkadd','checkedit','checkdelete','checkmove','linksubtask','discussionpost','attachmentadd','resultattachmentadd'],true)){
            $model=task_service::context($uuid,$actor,$selected,$scope,['recordonly'=>true,'safesources'=>true,'noformoptions'=>true,'nodiscussion'=>!in_array($tab,['overview','discussion'],true)]);
            $model['plans']=battle_plan_service::task_plan_context($uuid,$actor,$selected);$model['parent']=null;
            $model['can_add_subtasks'] = task_service::can_add_subtasks($uuid,$actor,$selected);
            if ($view === 'detail') {
                $model['subtasks'] = task_service::subtasks_page($uuid,$actor,$selected,
                    optional_param('subpage',0,PARAM_INT));
                $weekparam = optional_param('subweek', '', PARAM_RAW_TRIMMED);
                $weekstart = battle_plan_service::week_start($weekparam !== '' ? $weekparam : battle_plan_service::user_today());
                $model['subtask_week'] = task_service::subtask_week_plan_projection($uuid,$actor,$selected,$weekstart);
                $model['subtask_plan_memberships'] = battle_plan_service::task_plan_memberships($uuid,$actor,
                    array_column($model['subtasks']['items'], 'uuid'));
            }
        }
    }
}
$PAGE->set_context($context);
$PAGE->set_url(ui::url($uuid,['view'=>$view,'task'=>$selected,'scope'=>$scope,'tab'=>$tab]));
$PAGE->set_title(ui::tr('taskcenter_title'));
$PAGE->set_heading(ui::tr('pluginname'));
$PAGE->requires->js(new moodle_url('/local/kbacompany/js/task_ui.js'));
$PAGE->requires->js(new moodle_url('/local/kbacompany/js/task_discussion.js'));
$shell=\local_kbacompany\local\bms_page_shell_service::begin($uuid,$actor,'tasks',ui::tr('taskcenter_title'));
echo html_writer::start_div((\local_kbacompany\local\bms24_shell_service::is_enabled() ? 'bms-tui bms24-task-center' : 'bms-tui') .
    ($view==='list'?' bms24-task-listing':''),
    ['data-task-create-url'=>ui::url($uuid,['view'=>'create'])->out(false)]);
if($error!==''){echo html_writer::div(s($error),'alert alert-danger',['role'=>'alert']);}
if($view==='list'){
    $queryfilters = $filters;
    if ($display === 'deadlines') { $queryfilters['deadlineview'] = true; }
    $list=task_service::list_page($uuid,$actor,$queryfilters,optional_param('page',0,PARAM_INT));
    if (in_array($display, ['analytics','manager'], true)) {
        $list['execution_report'] = task_service::execution_report($uuid, $actor, $filters);
    } else if ($display === 'deadlines') {
        $list['deadline_summary'] = task_service::deadline_summary($uuid, $actor, $filters);
    }
    // Flash result is bound to both the current company and actor; never show another user's batch data.
    if (isset($SESSION->bms_task1842_result)) {
        $flash = $SESSION->bms_task1842_result;
        unset($SESSION->bms_task1842_result);
        if (($flash['company'] ?? '') === $uuid && (int)($flash['actor'] ?? 0) === $actor &&
                (int)($flash['time'] ?? 0) >= time() - 120) {
            $list['batch_result'] = $flash['data'];
        }
    }
    $list['actor']=$actor;
    $list['list_preferences']=$listprefs;
    if (in_array($display, ['list','kanban'], true)) {
        $list['recent_activity'] = task_service::latest_page_activity($uuid, $actor, $list['tasks'], $list['employees']);
    }
    if ($display === 'kanban') {
        $list['kanban_preferences'] = \local_kbacompany\local\task_kanban_preferences_service::current($uuid, $actor);
        $list['kanban_status_counts'] = task_service::kanban_status_counts($uuid, $actor, $filters);
    }
    $list['scope_counts']=task_service::scope_counts($uuid,$actor);
    $list['filter_plans']=$filterplans;
    $list['filter_plan_label']=$filterplan!==''?(string)($filterplans[$filterplan]??''):'';
    $list['plan_memberships']=battle_plan_service::task_plan_memberships($uuid,$actor,
        array_column($list['tasks'],'uuid'));
    echo ui::listing($uuid,$list,$filters,$display);
}else if($view==='create'||$view==='edit'){
    if(!$values){
        $values=ui::form_values($model['selected'],$actor,$model['employees']);
        $model['prefillparent'] = null;
        // Safe presentation-only prefill for canonical Task Center create links. GET never creates a task;
        // task_service validation remains authoritative on POST.
        if($view==='create' && ($method==='GET'||$method==='HEAD')){
            $createparent=optional_param('parent','',PARAM_RAW_TRIMMED);
            if ($createparent !== '') {
                if (!task_service::can_add_subtasks($uuid,$actor,$createparent)) {
                    throw new moodle_exception('taskcenter_parent','local_kbacompany');
                }
                $parentdto=task_service::readable_projection($uuid,$actor,$createparent);
                if (!$parentdto) { throw new moodle_exception('taskcenter_parent','local_kbacompany'); }
                $model['prefillparent']=$parentdto;
                $values['relationref']='task|'.$createparent.'|0';
                $values['stream']=$parentdto['stream'];
            }

            $prefillassignee=optional_param('assigneeid',0,PARAM_INT);
            $prefillreviewer=optional_param('reviewerid',0,PARAM_INT);
            $prefillobserver=optional_param('observerid',0,PARAM_INT);
            $prefillstart=optional_param('startdate','',PARAM_RAW_TRIMMED);
            $prefilldue=optional_param('duedate','',PARAM_RAW_TRIMMED);
            $prefillplan=optional_param('battleplanuuid','',PARAM_RAW_TRIMMED);
            $prefilltitle=trim(optional_param('title','',PARAM_TEXT));
            $prefilldescription=trim(optional_param('description','',PARAM_RAW_TRIMMED));
            if($prefillassignee>0 && isset($model['employees'][$prefillassignee])){$values['assigneeid']=$prefillassignee;}
            if($prefillreviewer>0 && isset($model['employees'][$prefillreviewer])){$values['reviewerid']=$prefillreviewer;}
            if($prefillobserver>0 && isset($model['employees'][$prefillobserver])){$values['observerid']=$prefillobserver;}
            foreach(['startdate'=>$prefillstart,'duedate'=>$prefilldue] as $field=>$date){
                if($date!=='' && preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date)){$values[$field]=$date;}
            }
            if($prefillplan!=='' && isset(($model['creation_plans']??[])[$prefillplan])){$values['battleplanuuid']=$prefillplan;}
            if($prefilltitle!==''){$values['title']=core_text::substr($prefilltitle,0,255);}
            if($prefilldescription!==''){$values['description']=core_text::substr($prefilldescription,0,12000);}
        }
    }
    echo ui::edit_form($uuid,$model,$values,$errors,$requestkey);
}else if($view==='linksubtask'){
    if (empty($model['can_add_subtasks'])) { throw new moodle_exception('taskcenter_permission','local_kbacompany'); }
    $candidates=task_service::subtask_candidates($uuid,$actor,$selected,
        optional_param('subsearch','',PARAM_RAW_TRIMMED));
    echo ui::link_subtask_form($uuid,$model,$candidates,$requestkey,
        optional_param('subsearch','',PARAM_RAW_TRIMMED));
}else if($view==='action'){
    $valid=!empty($permissions[$operation]);
    if($operation==='addbattleplan'){$valid=!empty($model['plans']['eligible']);}
    if(in_array($operation,['attachmentdelete','resultattachmentdelete'],true)){
        $name=$values['filename']??optional_param('filename','',PARAM_FILE);$values['filename']=$name;
        foreach($model[$operation==='attachmentdelete'?'attachments':'result_attachments'] as $file){if($file['filename']===$name&&!empty($file['can_delete'])){$valid=true;}}
    }
    if(!$valid||!in_array($operation,$confirmops,true)){echo html_writer::div(ui::tr('taskcenter_permission'),'alert alert-warning');echo ui::link(ui::url($uuid,['task'=>$selected]),ui::tr('tui_back_record'));}
    else{echo ui::action_form($uuid,$model,$operation,$values,$errors,$requestkey);}
}else if($view==='detail'){
    echo ui::detail($uuid,$model,$tab,$permissions,$values);
}else{
    require(__DIR__.'/templates/task_sources.php');
}
echo html_writer::end_div();
\local_kbacompany\local\bms_ai_workspace_service::render($uuid, 'task', 'Задачи');
\local_kbacompany\local\bms_page_shell_service::finish($shell);
