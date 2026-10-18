<?php
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\development_plan_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$userid = required_param('userid', PARAM_INT);
$planuuid = optional_param('plan', '', PARAM_RAW_TRIMMED);
$action = optional_param('action', '', PARAM_ALPHA);

if ($action !== '') {
    require_sesskey();
    if ($action === 'create') {
        $targetdate = optional_param('targetdate', '', PARAM_RAW_TRIMMED);
        $ts = $targetdate !== '' ? strtotime($targetdate . ' 23:59:59') : 0;
        $newuuid = development_plan_service::create_from_confirmed_gaps($uuid, $userid, (int)$USER->id,
            optional_param('title', '', PARAM_TEXT), $ts ?: 0);
        redirect(new moodle_url('/local/kbacompany/development_plan.php', ['uuid'=>$uuid,'userid'=>$userid,'plan'=>$newuuid]));
    }
    if ($action === 'item') {
        $duedate = optional_param('duedate', '', PARAM_RAW_TRIMMED);
        development_plan_service::save_item($uuid, $planuuid, (int)$USER->id, required_param('itemid', PARAM_INT),
            required_param('itemtype', PARAM_ALPHANUMEXT), required_param('title', PARAM_TEXT), optional_param('description', '', PARAM_TEXT),
            $duedate !== '' ? (strtotime($duedate . ' 23:59:59') ?: 0) : 0, required_param('responsibleid', PARAM_INT),
            optional_param('courseid', 0, PARAM_INT), optional_param('learningurl', '', PARAM_URL),
            required_param('status', PARAM_ALPHANUMEXT), optional_param('evidence', '', PARAM_TEXT));
        redirect(new moodle_url('/local/kbacompany/development_plan.php', ['uuid'=>$uuid,'userid'=>$userid,'plan'=>$planuuid]),
            get_string('developmentplan_saved', 'local_kbacompany'));
    }
    if ($action === 'activate' || $action === 'complete' || $action === 'cancel') {
        $map = ['activate'=>'active','complete'=>'completed','cancel'=>'cancelled'];
        development_plan_service::transition($uuid, $planuuid, (int)$USER->id, $map[$action]);
        redirect(new moodle_url('/local/kbacompany/development_plan.php', ['uuid'=>$uuid,'userid'=>$userid,'plan'=>$planuuid]));
    }
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacompany/development_plan.php', ['uuid'=>$uuid,'userid'=>$userid,'plan'=>$planuuid]));
$PAGE->set_title(get_string('developmentplan_title', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));
$shell = \local_kbacompany\local\bms_page_shell_service::begin($uuid, (int)$USER->id, 'employees', get_string('developmentplan_title', 'local_kbacompany'));

$back = new moodle_url('/local/kbacompany/employee_competencies.php', ['uuid'=>$uuid,'userid'=>$userid]);
echo html_writer::div(html_writer::link($back, '← '.get_string('employeecompetency_title','local_kbacompany')), 'mb-3');

if ($planuuid === '') {
    $plans = development_plan_service::list_for_employee($uuid, $userid, (int)$USER->id);
    echo $OUTPUT->heading(get_string('developmentplan_title', 'local_kbacompany'), 2);
    echo html_writer::tag('p', get_string('developmentplan_intro', 'local_kbacompany'), ['class'=>'text-muted']);
    $profile = \local_kbacompany\local\employee_competency_profile_service::context($uuid, $userid, (int)$USER->id);
    if ($profile['can_manage']) {
        $form = html_writer::start_tag('form', ['method'=>'post','class'=>'card card-body mb-3']);
        foreach (['uuid'=>$uuid,'userid'=>$userid,'action'=>'create','sesskey'=>sesskey()] as $n=>$v) { $form .= html_writer::empty_tag('input',['type'=>'hidden','name'=>$n,'value'=>$v]); }
        $form .= html_writer::tag('label', get_string('developmentplan_name','local_kbacompany'), ['class'=>'small font-weight-bold']);
        $form .= html_writer::empty_tag('input',['name'=>'title','class'=>'form-control form-control-sm mb-2','value'=>get_string('developmentplan_default_title','local_kbacompany')]);
        $form .= html_writer::tag('label', get_string('developmentplan_targetdate','local_kbacompany'), ['class'=>'small font-weight-bold']);
        $form .= html_writer::empty_tag('input',['type'=>'date','name'=>'targetdate','class'=>'form-control form-control-sm mb-2']);
        $form .= html_writer::tag('button', get_string('developmentplan_create_from_gaps','local_kbacompany'), ['class'=>'btn btn-primary btn-sm','type'=>'submit']);
        $form .= html_writer::end_tag('form'); echo $form;
    }
    if (!$plans) { echo $OUTPUT->notification(get_string('developmentplan_empty','local_kbacompany'),'info'); }
    else {
        $table = new html_table(); $table->attributes['class']='table table-sm';
        $table->head=[get_string('developmentplan_name','local_kbacompany'),get_string('status'),get_string('developmentplan_targetdate','local_kbacompany'),get_string('actions')];
        foreach ($plans as $p) {
            $url=new moodle_url('/local/kbacompany/development_plan.php',['uuid'=>$uuid,'userid'=>$userid,'plan'=>$p->uuid]);
            $table->data[]=[html_writer::link($url,s($p->title)),get_string('developmentplan_status_'.$p->status,'local_kbacompany'),$p->targetdate?userdate($p->targetdate,get_string('strftimedatefullshort')):'—',html_writer::link($url,get_string('view'))];
        }
        echo html_writer::div(html_writer::table($table),'table-responsive');
    }
} else {
    $data = development_plan_service::context($uuid, $planuuid, (int)$USER->id); $plan=$data['plan'];
    echo $OUTPUT->heading(s($plan->title),2,'mb-1');
    echo html_writer::tag('p', get_string('developmentplan_status_'.$plan->status,'local_kbacompany'), ['class'=>'text-muted']);
    if ($data['can_manage']) {
        $buttons='';
        if ($plan->status==='draft') $buttons .= html_writer::link(new moodle_url('/local/kbacompany/development_plan.php',['uuid'=>$uuid,'userid'=>$userid,'plan'=>$planuuid,'action'=>'activate','sesskey'=>sesskey()]),get_string('developmentplan_activate','local_kbacompany'),['class'=>'btn btn-primary btn-sm mr-2']);
        if ($plan->status==='active') $buttons .= html_writer::link(new moodle_url('/local/kbacompany/development_plan.php',['uuid'=>$uuid,'userid'=>$userid,'plan'=>$planuuid,'action'=>'complete','sesskey'=>sesskey()]),get_string('developmentplan_complete','local_kbacompany'),['class'=>'btn btn-success btn-sm mr-2']);
        if (in_array($plan->status,['draft','active'],true)) $buttons .= html_writer::link(new moodle_url('/local/kbacompany/development_plan.php',['uuid'=>$uuid,'userid'=>$userid,'plan'=>$planuuid,'action'=>'cancel','sesskey'=>sesskey()]),get_string('developmentplan_cancel','local_kbacompany'),['class'=>'btn btn-outline-secondary btn-sm']);
        echo html_writer::div($buttons,'mb-3');
    }
    echo html_writer::tag('p', get_string('developmentplan_boundary_notice','local_kbacompany'), ['class'=>'alert alert-info small']);
    echo html_writer::tag('p', get_string('learninglink_boundary_notice','local_kbacompany'), ['class'=>'text-muted small']);
    foreach ($data['items'] as $entry) {
        $i=$entry['record'];
        echo html_writer::start_div('card mb-3'); echo html_writer::start_div('card-body');
        echo html_writer::tag('div', format_string($entry['competency']), ['class'=>'small text-muted mb-1']);
        echo html_writer::tag('h5', s($i->title), ['class'=>'mb-2']);
        echo html_writer::tag('div', get_string('developmentplan_levels','local_kbacompany',(object)['from'=>$i->baselinelevel,'to'=>$i->targetlevel]), ['class'=>'small mb-2']);
        if (!empty($entry['learning'])) {
            $learning = $entry['learning'];
            $label = get_string('learninglink_status_' . $learning['status'], 'local_kbacompany');
            $course = !empty($learning['name']) ? format_string($learning['name']) : ('#' . (int)$learning['course_id']);
            $summary = get_string('learninglink_live_status', 'local_kbacompany', (object)['course'=>$course,'status'=>$label]);
            if (!empty($learning['url'])) {
                $summary .= ' · ' . html_writer::link($learning['url'], get_string('learninglink_open_course','local_kbacompany'), ['target'=>'_blank','rel'=>'noopener']);
            }
            echo html_writer::div($summary, 'alert alert-light py-2 px-3 small mb-2');
        }
        if ($data['can_manage'] && !in_array($plan->status,['completed','cancelled'],true)) {
            $f=html_writer::start_tag('form',['method'=>'post']);
            foreach(['uuid'=>$uuid,'userid'=>$userid,'plan'=>$planuuid,'action'=>'item','itemid'=>$i->id,'sesskey'=>sesskey()] as $n=>$v) $f.=html_writer::empty_tag('input',['type'=>'hidden','name'=>$n,'value'=>$v]);
            $opts=''; foreach(development_plan_service::item_types() as $t) $opts.=html_writer::tag('option',get_string('developmentplan_type_'.$t,'local_kbacompany'),['value'=>$t,'selected'=>$i->itemtype===$t?'selected':null]);
            $f.=html_writer::tag('select',$opts,['name'=>'itemtype','class'=>'form-control form-control-sm mb-2']);
            $f.=html_writer::empty_tag('input',['name'=>'title','value'=>$i->title,'class'=>'form-control form-control-sm mb-2']);
            $f.=html_writer::tag('textarea',s($i->description),['name'=>'description','rows'=>2,'class'=>'form-control form-control-sm mb-2','placeholder'=>get_string('developmentplan_description','local_kbacompany')]);
            $f.=html_writer::empty_tag('input',['type'=>'date','name'=>'duedate','value'=>$i->duedate?date('Y-m-d',$i->duedate):'','class'=>'form-control form-control-sm mb-2']);
            $f.=html_writer::empty_tag('input',['type'=>'number','name'=>'responsibleid','min'=>1,'value'=>$i->responsibleid,'class'=>'form-control form-control-sm mb-2']);
            $courseopts=html_writer::tag('option', get_string('learninglink_no_course','local_kbacompany'), ['value'=>0]);
            foreach ($data['learning_catalogue'] as $course) {
                $cid=(int)($course['courseid']??0);
                if ($cid<=1) continue;
                $label=(string)($course['fullname']??$course['shortname']??('#'.$cid));
                $courseopts.=html_writer::tag('option', format_string($label), ['value'=>$cid,'selected'=>(int)$i->courseid===$cid?'selected':null]);
            }
            $f.=html_writer::tag('label', get_string('learninglink_course','local_kbacompany'), ['class'=>'small font-weight-bold']);
            $f.=html_writer::tag('select',$courseopts,['name'=>'courseid','class'=>'form-control form-control-sm mb-2']);
            $f.=html_writer::empty_tag('input',['type'=>'url','name'=>'learningurl','value'=>$i->learningurl,'class'=>'form-control form-control-sm mb-2','placeholder'=>get_string('developmentplan_learningurl','local_kbacompany')]);
            $st=''; foreach(['planned','in_progress','completed','cancelled'] as $s) $st.=html_writer::tag('option',get_string('developmentplan_itemstatus_'.$s,'local_kbacompany'),['value'=>$s,'selected'=>$i->status===$s?'selected':null]);
            $f.=html_writer::tag('select',$st,['name'=>'status','class'=>'form-control form-control-sm mb-2']);
            $f.=html_writer::tag('textarea',s($i->evidence),['name'=>'evidence','rows'=>2,'class'=>'form-control form-control-sm mb-2','placeholder'=>get_string('developmentplan_evidence','local_kbacompany')]);
            $f.=html_writer::tag('button',get_string('savechanges'),['type'=>'submit','class'=>'btn btn-primary btn-sm']); $f.=html_writer::end_tag('form'); echo $f;
        } else {
            echo html_writer::tag('div',get_string('developmentplan_itemstatus_'.$i->status,'local_kbacompany'),['class'=>'badge badge-light']);
        }
        echo html_writer::end_div(); echo html_writer::end_div();
    }
}
\local_kbacompany\local\bms_page_shell_service::finish($shell);
