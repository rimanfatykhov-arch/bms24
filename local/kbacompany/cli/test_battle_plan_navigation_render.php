<?php
/** Lightweight mock of HTML primitives. Only tests nav escaping and URL flow; not Moodle integration. */
define('MOODLE_INTERNAL', true);
function s($value) {return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');}
function get_string($key, $component='') {return $key;}
class moodle_url {
    private $path; private $params;
    public function __construct($path, $params=[]) {$this->path=$path;$this->params=$params;}
    public function __toString(): string {return $this->out(false);}
    public function out($escaped=true) {return $this->path.($this->params?'?'.http_build_query($this->params):'');}
}
class html_writer {
    public static function tag($tag,$body,$attrs=[]) {return '<'.$tag.self::attrs($attrs).'>'.$body.'</'.$tag.'>';}
    public static function link($url,$label,$attrs=[]) {return '<a href="'.s((string)$url).'"'.self::attrs($attrs).'>'.$label.'</a>';}
    public static function div($body,$class='') {return self::tag('div',$body,['class'=>$class]);}
    public static function span($body,$class='') {return self::tag('span',$body,['class'=>$class]);}
    private static function attrs($attrs) { $o='';foreach ($attrs as $name=>$value) {if ($value!==null) {$o.=' '.s($name).'="'.s($value).'"';}}return $o;}
}
class battle_plan_service_mock {
    public static function period_label($start,$end) {return $start.' — '.$end;}
}
class_alias('battle_plan_service_mock', 'local_kbacompany\\local\\battle_plan_service');
require_once(__DIR__.'/../classes/local/battle_plan_navigation_service.php');
require_once(__DIR__.'/../classes/local/battle_plan_ui_service.php');
use local_kbacompany\local\battle_plan_ui_service as ui;
$ctx=['selected'=>['uuid'=>'plan-1','period_start'=>'2026-10-19','period_end'=>'2026-10-25',
    'status'=>'submitted','user_id'=>3,'reviewer_id'=>4],
    'employees'=>[3=>'<script>alert(1)</script>'], 'selected_can_edit'=>false,'can_manage'=>false];
$queue=new moodle_url('/local/kbacompany/battle_plan_overview.php', ['uuid'=>'company-1','week'=>'2026-10-19']);
$context=['fromqueue'=>1,'qweek'=>'2026-10-19','qfilter'=>'review','qpage'=>2];
$html=ui::record_navigation('company-1',$ctx,'detail','history',$context,$queue);
if (strpos($html,'<script>')!==false || strpos($html,'&lt;script&gt;')===false) {
    throw new RuntimeException('Employee display name must be escaped');
}
if (strpos($html, 'bpu_nav_edit')!==false || strpos($html, 'bpu_nav_fill')!==false) {
    throw new RuntimeException('Read-only context must have no edit or fill action');
}
if (strpos($html,'qpage=2')===false || strpos($html,'bpu_approval_back_queue')===false) {
    throw new RuntimeException('Whitelisted return queue context must survive');
}
if (substr_count($html,'aria-current="page"')!==1) {
    throw new RuntimeException('Exactly one active tab');
}
$ctx['selected_can_edit']=true;
$ctx['selected']['status']='draft';
$html=ui::record_navigation('company-1',$ctx,'fill','tasks',[],null);
if (strpos($html,'bpu_nav_fill')===false || strpos($html,'bpu_nav_edit')===false) {
    throw new RuntimeException('Writer needs edit and add-task shortcuts');
}
if (strpos($html,'bpu_approval_back_queue')!==false) {
    throw new RuntimeException('Queue return must not leak without validated URL');
}
echo "battle plan navigation HTML rendering PASS (mocked Moodle HTML)\n";
