<?php
namespace {
    define('MOODLE_INTERNAL', true);
    class moodle_exception extends \Exception {}
    class required_capability_exception extends \Exception {}
    class coding_exception extends \Exception {}
    class context_system { public static function instance() { return new self(); } }
}
namespace local_kbacompany\local {
    class company_context_service {
        public static function normalise_uuid(string $v): string { return strtolower(trim($v)); }
        public static function context(string $v, int $u): array { return ['uuid'=>self::normalise_uuid($v),'name'=>'Test Co','active'=>1]; }
    }
    class permission_service {
        public const CAPABILITY_MANAGE='local/kbacompany:manageorganization';
        public static function can_manage_organization(string $c,int $u): bool { return $u===7; }
    }
    class integration_event_service {
        public static function contract(): array { return ['version'=>'test']; }
        public static function diagnostics(string $c): array { return ['summary'=>['pending'=>2,'error'=>1,'done'=>3]]; }
    }
    class integration_service {
        public static $legacy='99';
        public static function external_id(string $p,string $k,string $l): string { return self::$legacy; }
        public static function mapping_by_local(string $p,string $k,string $l): ?\stdClass {
            if (self::$legacy==='') return null;
            return (object)['mappinguuid'=>null,'externalid'=>self::$legacy,'timecreated'=>1,'timemodified'=>2];
        }
    }
    class public_api_service {
        public static function company_snapshot(string $c,int $u): array { return ['resource'=>'company','schema_version'=>'1','snapshot_hash'=>'hc','data'=>[]]; }
        public static function employees_snapshot(string $c,int $u,bool $i=false,bool $contact=false): array { return ['resource'=>'employees','schema_version'=>'1','snapshot_hash'=>'he','data'=>['count'=>2,'employees'=>[1,2]]]; }
        public static function positions_snapshot(string $c,int $u): array { return ['resource'=>'positions','schema_version'=>'1','snapshot_hash'=>'hp','data'=>['summary'=>['positions'=>3],'positions'=>[1,2,3]]]; }
    }
    class fake_db {
        public $scoped=true;
        public function get_records_sql($sql,$params,$from=0,$num=0) { return $this->scoped ? [(object)['id'=>1,'environment'=>'prod']] : []; }
        public function get_record($table,$conditions,$fields='*') {
            if (($conditions['environment']??'')==='prod' && ($conditions['entitykind']??'')==='company') return (object)['id'=>1,'mappinguuid'=>'map-c','externalid'=>'42','namespacekey'=>'ns','externalmodel'=>'crm.company'];
            return false;
        }
        public function count_records($table,$conditions) {
            if ($table==='local_kbacompany_syncout') return ($conditions['status']??'')==='pending' ? 4 : (($conditions['status']??'')==='error' ? 1 : 0);
            $k=$conditions['entitykind']??''; return ['company'=>1,'position'=>2,'indicator'=>3][$k]??0;
        }
        public function get_records($table,$conditions,$sort='',$fields='*',$from=0,$num=0) {
            return [(object)['id'=>1,'mappinguuid'=>'m1','entitykind'=>'company','localref'=>'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa','externalmodel'=>'crm.company','externalid'=>'42','timecreated'=>1,'timemodified'=>2]];
        }
    }
}
namespace {
    $DB=new \local_kbacompany\local\fake_db();
    require __DIR__.'/../classes/local/bitrix_adapter_service.php';
    $s='local_kbacompany\\local\\bitrix_adapter_service';
    $checks=0;
    $assert=function($cond,$msg) use (&$checks){$checks++; if(!$cond){fwrite(STDERR,"FAIL: $msg\n"); exit(1);}};
    $c=$s::contract();
    $assert($c['version']==='0.9.2','contract version');
    $assert($c['bitrix_rest_owner']==='local_bitrixsync_kba_connect','transport owner');
    $assert($c['direct_bitrix_rest_from_bms']===false,'no direct REST');
    $assert($c['credentials_in_local_kbacompany']===false,'no secrets');
    $env=$s::environments('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',7);
    $assert($env===['prod','legacy'],'scoped + legacy environments');
    $r=$s::readiness('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',7,'prod');
    $assert($r['company_mapping']['external_id']==='42','scoped company mapping');
    $assert($r['legacy_command_outbox']['pending']===4,'pending queue count');
    $m=$s::manifest('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',7,'prod');
    $assert($m['resources']['employees']['count']===2,'employee manifest count');
    $assert($m['resources']['positions']['count']===3,'position manifest count');
    $legacy=$s::mappings('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',7,'legacy',0);
    $assert($legacy['total']===1 && $legacy['legacy_child_mappings_enumerated']===false,'legacy company-only mapping');
    $paged=$s::mappings('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',7,'prod',0);
    $assert($paged['mappings'][0]['identity_mode']==='scoped','scoped mapping page');
    echo "PASS {$checks} checks\n";
}
