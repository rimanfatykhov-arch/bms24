<?php
/** Pure read-only Corporate bridge integration smoke with fixed fake data, no Moodle DB. */
namespace {
    define('MOODLE_INTERNAL', true);
    class moodle_url {
        private $p; private $params;
        public function __construct($path, $params = []) { $this->p=$path; $this->params=$params; }
        public function out($escaped=false) { return $this->p.'?'.http_build_query($this->params); }
    }
    function get_string($key, $component) { return $key; }
    class FakeDB {
        public $bindings = [];
        public function record_exists($table, $conditions) {
            if ($table !== 'local_kbacompany_tasksrc') { throw new \Exception('Unwanted DB query'); }
            $key = $conditions['companyuuid'].'|'.$conditions['provider'].'|'.$conditions['sourcekey'];
            return !empty($this->bindings[$key]);
        }
    }
    $DB=new FakeDB();
}
namespace local_kbacompany\local {
    class company_context_service {
        public static function context($uuid, $actor) {
            if ($actor < 1) { throw new \Exception('access'); }
            switch ($uuid) {
                case 'a': return ['active'=>true, 'external_company_id'=>111,
                    'compatibility'=>['source_component'=>'local_kbacorporate','source_id'=>4]];
                case 'b': return ['active'=>true, 'external_company_id'=>222,
                    'compatibility'=>['source_component'=>'local_kbacorporate','source_id'=>8]];
                case 'wrong': return ['active'=>true, 'external_company_id'=>222,
                    'compatibility'=>['source_component'=>'local_kbacorporate','source_id'=>4]];
                default: throw new \Exception('not BMS company');
            }
        }
    }
    class task_service { public static function can_manage($uuid, $actor) { return $actor === 1; } }
}
namespace local_kbacorporate\local {
    class bridge {
        public const API_RELEASE='1.22';
        public static function get_company_by_bitrix_id($id) {
            if ($id === 111) { return ['found'=>true, 'active'=>true,'companyid'=>4,'bitrixcompanyid'=>111]; }
            if ($id === 222) { return ['found'=>true, 'active'=>true,'companyid'=>8,'bitrixcompanyid'=>222]; }
            return ['found'=>false];
        }
        public static function list_implementation_task_projections($companyid,$actor,$target,$scope,$limit,$offset) {
            if ($scope==='company' && $actor!==1) { throw new \Exception('Corporate denied manager'); }
            if ($scope==='mine' && $target!==$actor) { throw new \Exception('Corporate denied foreign task'); }
            if ($offset>0) { return []; }
            if ($companyid===4) { return [[
                'external_id'=>'impltask:9','company_id'=>4, 'title'=>'Внедрение BMS',
                'assignee_id'=>($scope==='company'?2:$target),'status'=>'inprogress',
                'program_title'=>'Программа внедрения','priority'=>'normal','attachable'=>true,
                'reviewer_id'=>1,'creator_id'=>1,
            ]]; }
            return [[ 'external_id'=>'impltask:21','company_id'=>8,'title'=>'Другая компания',
                'assignee_id'=>$target, 'status'=>'review','attachable'=>false ]];
        }
        public static function get_implementation_task_projection($companyid,$actor,$id) {
            foreach (self::list_implementation_task_projections($companyid,$actor,$actor,'mine',1,0) as $row) {
                if ((int)substr($row['external_id'],9)===$id) { return $row; }
            }
            return null;
        }
    }
}
namespace local_kbacompany\local\task_provider {
    require __DIR__.'/../classes/local/task_provider/task_provider_interface.php';
    require __DIR__.'/../classes/local/task_provider/kbacorporate_impl_task_provider.php';
}
namespace {
    $tests=0;
    $check=static function($condition,$name)use(&$tests){$tests++;if(!$condition){throw new \Exception('FAILED: '.$name);}};
    $p = new \local_kbacompany\local\task_provider\kbacorporate_impl_task_provider();
    $check($p->available(),'public bridge 1.22 present');
    $check($p->available_sources('a',1)[0]['source_key']==='company:4','verified source A');
    $check($p->available_sources('wrong',1)===[],'reject disagreeing legacy and bitrix IDs');
    $check($p->available_sources('a',2)===[],'only manager may bind');
    $check($p->list_tasks('a',2,2,'my')===[],'disabled by default');
    $DB->bindings['a|kbacorporate_impl|company:4']=true;
    $DB->bindings['b|kbacorporate_impl|company:8']=true;
    $rows=$p->list_tasks('a',2,2,'my');
    $check(count($rows)===1,'one own task');
    $check($rows[0]['status']==='doing','status mapped');
    $check($rows[0]['canonical_task_uuid']==='','no copied BMS task');
    $check($rows[0]['editable']===false,'read only');
    $check($rows[0]['attachable']===true,'eligible planning');
    $check($rows[0]['source_url']==='/local/kbacorporate/implementation_task.php?id=9','safe internal URL');
    $check(count($p->list_tasks('a',1,0,'all'))===1,'manager company source');
    $check($p->list_tasks('a',2,1,'all')===[],'employee cannot query others');
    $check($p->list_tasks('wrong',1,0,'all')===[],'reject mismatched mapped company');
    $check(count($p->list_tasks('b',1,0,'all'))===1,'separate company');
    $check($p->get_task('a',2,'impltask:21')===null,'reject another company ID');
    $check($p->get_task('a',2,'impltask:9')['external_id']==='impltask:9','source read');
    $check($p->get_task('a',2,'impltask:0')===null,'reject zero ID');
    $check($p->get_task('a',2,'https://evil')===null,'reject URL ID');
    $check(\local_kbacompany\local\task_provider\kbacorporate_impl_task_provider::project([
      'external_id'=>'impltask:9','company_id'=>8,'assignee_id'=>2,'status'=>'planned'],4)===null,'reject forged company from source');
    $check(\local_kbacompany\local\task_provider\kbacorporate_impl_task_provider::status_value('completed')==='done','completed');
    $check(\local_kbacompany\local\task_provider\kbacorporate_impl_task_provider::status_value('???')==='unknown','unknown fail closed');
    echo "Corporate/BMS provider pure smoke: {$tests}/{$tests} PASS\n";
}
