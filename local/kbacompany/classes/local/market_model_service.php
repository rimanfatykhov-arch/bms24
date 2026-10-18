<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Market, customer segments, value propositions, portfolio and company business-model design. */
class market_model_service {
    public const CONTRACT_VERSION = '0.4.11';
    public const STATES = ['draft', 'published', 'archived'];
    public const OFFER_TYPES = ['product', 'service', 'solution'];

    public static function contract(): array {
        $contract = [
            'component' => 'local_kbacompany', 'contract' => 'bms_market_business_model',
            'version' => self::CONTRACT_VERSION, 'company_scope' => 'required',
            'objects' => ['Market', 'CustomerSegment', 'Offering', 'BusinessModelCanvas2'],
            'rules' => ['market_and_customer_are_explicit', 'value_proposition_is_segment_specific',
                'offering_may_reference_bms_product_and_core_process', 'published_revisions_are_immutable',
                'strategy_and_financial_links_are_explicit_not_inferred', 'owner_requirements_are_explicit_and_revision_pinned',
                'bmc_blocks_keep_current_and_target_state_separate', 'bmc_crud_items_are_revision_snapshot_scoped',
                'delete_is_audited_archive_revision', 'no_crm_or_erp_fact_mutation'],
        ];
        $contract['schema_hash_algorithm'] = 'sha256';
        $contract['schema_hash'] = hash('sha256', json_encode($contract, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $contract;
    }

    public static function can_manage(string $companyuuid, int $userid): bool {
        return permission_service::can_manage_management($companyuuid, $userid);
    }
    private static function authorise(string $companyuuid, int $userid): void {
        company_context_service::context($companyuuid, $userid);
        if (!self::can_manage($companyuuid, $userid)) {
            throw new \required_capability_exception(\context_system::instance(), 'local/kbacompany:manageorganization', 'nopermissions', '');
        }
    }
    private static function fail(string $key): void { throw new \moodle_exception('marketmodel_' . $key, 'local_kbacompany'); }
    private static function uuid(): string {
        $b=random_bytes(16); $b[6]=chr((ord($b[6])&0x0f)|0x40); $b[8]=chr((ord($b[8])&0x3f)|0x80); $h=bin2hex($b);
        return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);
    }
    private static function text(array $data, string $key, int $max, bool $required=false): string {
        $v=trim((string)($data[$key]??'')); if (($required&&$v==='') || \core_text::strlen($v)>$max) self::fail('invalid'); return $v;
    }
    private static function latest(string $table, string $companyuuid, string $uuid): \stdClass {
        global $DB; $r=$DB->get_records($table,['companyuuid'=>$companyuuid,'uuid'=>$uuid],'revision DESC','*',0,1);
        if(!$r) self::fail('reference'); return reset($r);
    }
    private static function current_rows(string $table, string $companyuuid, bool $canmanage): array {
        global $DB; $g=[]; foreach($DB->get_records($table,['companyuuid'=>$companyuuid],'uuid ASC, revision ASC') as $r){$g[$r->uuid][]=$r;}
        $out=[]; foreach($g as $versions){$latest=end($versions); if($latest->status==='archived')continue;
            if($canmanage){$out[]=$latest;continue;} foreach(array_reverse($versions) as $c){if($c->status==='published'){$out[]=$c;break;}}
        } return $out;
    }
    private static function save_versioned(string $table,string $companyuuid,int $userid,array $data,string $uuid,int $expected,array $fields,$afterinsert=null): string {
        global $DB; self::authorise($companyuuid,$userid); $lock=\core\lock\lock_config::get_lock_factory('local_kbacompany')->get_lock('market_model:'.$companyuuid,10);
        if(!$lock)self::fail('lock'); try{$tx=$DB->start_delegated_transaction(); $old=null;
            if($uuid!==''){$old=self::latest($table,$companyuuid,$uuid); if((int)$old->revision!==$expected)self::fail('conflict'); if($old->status==='archived')self::fail('state');}
            else if($expected!==0)self::fail('conflict'); $stable=$old?(string)$old->uuid:self::uuid(); $row=(object)['companyuuid'=>$companyuuid,'uuid'=>$stable,'revision'=>$old?((int)$old->revision+1):1,'status'=>'draft'];
            foreach($fields as $key=>[$max,$required]){$row->$key=self::text($data,$key,$max,$required);} $now=time(); $row->createdby=$userid;$row->modifiedby=$userid;$row->timecreated=$now;$row->timemodified=$now;
            $DB->insert_record($table,$row);if($afterinsert){$afterinsert($row);}$tx->allow_commit();return $stable;
        }catch(\Throwable $e){if(isset($tx))$tx->rollback($e);throw $e;}finally{$lock->release();}}
    private static function archive_versioned(string $table,string $companyuuid,int $userid,string $uuid,int $expected,$afterinsert=null): void {
        global $DB; self::authorise($companyuuid,$userid);$lock=\core\lock\lock_config::get_lock_factory('local_kbacompany')->get_lock('market_model:'.$companyuuid,10);if(!$lock)self::fail('lock');
        try{$tx=$DB->start_delegated_transaction();$old=self::latest($table,$companyuuid,$uuid);if((int)$old->revision!==$expected)self::fail('conflict');if($old->status==='archived')self::fail('state');
            $row=clone $old;unset($row->id);$row->revision=$expected+1;$row->status='archived';$row->modifiedby=$userid;$row->timemodified=time();$DB->insert_record($table,$row);if($afterinsert){$afterinsert($row);}$tx->allow_commit();
        }catch(\Throwable $e){if(isset($tx))$tx->rollback($e);throw $e;}finally{$lock->release();}}

    private static function canvas_payload(array $data, array $allowedreqs): array {
        $payload = [];
        foreach (['summary','horizon','ideology_first','ideology_target','ideology_criterion','ideology_reqs','ideology_facilitator'] as $key) {
            $payload[$key] = self::text($data,$key,12000,false);
        }
        $blocks=['segments','valueprop','relationships','channels','activities','resources','partners','revenue','costs'];
        $suffixes=['first','current','data','hyp','target','criterion','reqs','gap','facilitator'];
        foreach($blocks as $block){
            $payload[$block]=[];
            foreach($suffixes as $suffix){$payload[$block][$suffix]=self::text($data,$block.'_'.$suffix,12000,false);}
        }
        foreach(['market','client','economics','resources','ownerrequirements'] as $contour){
            $status=self::text($data,'check_'.$contour,20,false);
            if($status!==''&&!in_array($status,['confirmed','partial','hypothesis','needsdata','open'],true))self::fail('invalid');
            $payload['checks'][$contour]=$status;
            $payload['check_notes'][$contour]=self::text($data,'check_'.$contour.'_note',4000,false);
        }
        $allowed=array_fill_keys($allowedreqs,true);
        foreach($payload as $key=>$value){
            if(is_string($value)&&substr($key,-5)==='_reqs'){
                foreach(preg_split('/[\s,;\/|]+/',strtoupper($value),-1,PREG_SPLIT_NO_EMPTY) as $req){if(!isset($allowed[$req]))self::fail('ownerreq');}
            } else if(is_array($value)&&in_array($key,$blocks,true)){
                foreach(preg_split('/[\s,;\/|]+/',strtoupper((string)$value['reqs']),-1,PREG_SPLIT_NO_EMPTY) as $req){if(!isset($allowed[$req]))self::fail('ownerreq');}
            }
        }
        return $payload;
    }

    public static function business_model_history(string $c,int $u,string $uuid): array {
        global $DB; self::authorise($c,$u);$out=[];
        foreach($DB->get_records('local_kbacompany_bizmodel',['companyuuid'=>$c,'uuid'=>$uuid],'revision DESC') as $row){$out[]=self::dto($row);}return $out;
    }

    private static function publish_versioned(string $table,string $companyuuid,int $userid,string $uuid,int $expected,$afterinsert=null): void {
        global $DB; self::authorise($companyuuid,$userid);$lock=\core\lock\lock_config::get_lock_factory('local_kbacompany')->get_lock('market_model:'.$companyuuid,10);if(!$lock)self::fail('lock');
        try{$tx=$DB->start_delegated_transaction();$old=self::latest($table,$companyuuid,$uuid);if((int)$old->revision!==$expected)self::fail('conflict');if($old->status!=='draft')self::fail('state');
            $row=clone $old;unset($row->id);$row->revision=$expected+1;$row->status='published';$row->modifiedby=$userid;$row->timemodified=time();$DB->insert_record($table,$row);if($afterinsert){$afterinsert($row);}$tx->allow_commit();
        }catch(\Throwable $e){if(isset($tx))$tx->rollback($e);throw $e;}finally{$lock->release();}}

    public static function save_market(string $c,int $u,array $d,string $uuid='',int $rev=0): string {
        return company_context_domain_service::save_market($c, $u, $d, $uuid, $rev);
    }
    public static function publish_market(string $c,int $u,string $uuid,int $rev):void{
        company_context_domain_service::publish_market($c, $u, $uuid, $rev);
    }

    public static function save_segment(string $c,int $u,array $d,string $uuid='',int $rev=0): string {
        global $DB; $market=self::text($d,'marketuuid',36,true); if(!$DB->record_exists('local_kbacompany_market',['companyuuid'=>$c,'uuid'=>$market]))self::fail('reference');
        return self::save_versioned('local_kbacompany_segment',$c,$u,$d,$uuid,$rev,['marketuuid'=>[36,true],'code'=>[50,true],'name'=>[255,true],'needs'=>[4000,false],'problems'=>[4000,false],'profile'=>[4000,false]]);
    }
    public static function publish_segment(string $c,int $u,string $uuid,int $rev):void{self::publish_versioned('local_kbacompany_segment',$c,$u,$uuid,$rev);}

    public static function save_offering(string $c,int $u,array $d,string $uuid='',int $rev=0): string {
        global $DB; $type=self::text($d,'offertype',12,true); if(!in_array($type,self::OFFER_TYPES,true))self::fail('invalid');
        $seg=self::text($d,'segmentuuid',36,false); if($seg!==''&&!$DB->record_exists('local_kbacompany_segment',['companyuuid'=>$c,'uuid'=>$seg]))self::fail('reference');
        $proc=self::text($d,'coreprocessuuid',36,false); if($proc!==''){$rows=$DB->get_records('local_kbacompany_process',['companyuuid'=>$c,'uuid'=>$proc,'status'=>'published'],'revision DESC','*',0,1); if(!$rows||reset($rows)->processtype!=='core')self::fail('coreprocess');}
        $prod=self::text($d,'productuuid',36,false);if($prod!==''&&!$DB->record_exists('local_kbacompany_product',['companyuuid'=>$c,'uuid'=>$prod]))self::fail('reference');
        return self::save_versioned('local_kbacompany_offering',$c,$u,$d,$uuid,$rev,['code'=>[50,true],'name'=>[255,true],'offertype'=>[12,true],'segmentuuid'=>[36,false],'valueproposition'=>[4000,true],'productuuid'=>[36,false],'coreprocessuuid'=>[36,false]]);
    }
    public static function publish_offering(string $c,int $u,string $uuid,int $rev):void{self::publish_versioned('local_kbacompany_offering',$c,$u,$uuid,$rev);}

    private static function business_model_code(string $companyuuid, string $uuid): string {
        global $DB;
        if ($uuid !== '') {
            $rows = $DB->get_records('local_kbacompany_bizmodel', [
                'companyuuid' => $companyuuid, 'uuid' => $uuid,
            ], 'revision DESC', 'stablecode', 0, 1);
            if ($rows) {
                $existing = trim((string)reset($rows)->stablecode);
                if ($existing !== '') {
                    return $existing;
                }
            }
        }
        $minimum = 0;
        foreach ($DB->get_fieldset_select('local_kbacompany_bizmodel', 'stablecode',
                'companyuuid = :companyuuid AND stablecode <> :empty',
                ['companyuuid' => $companyuuid, 'empty' => '']) as $code) {
            if (preg_match('/^BMC-(\d+)$/', (string)$code, $matches)) {
                $minimum = max($minimum, (int)$matches[1]);
            }
        }
        return stable_code_service::next($companyuuid, 'businessmodel:canvas', 'BMC',
            static function(string $candidate) use ($DB, $companyuuid): bool {
                return !$DB->record_exists('local_kbacompany_bizmodel', [
                    'companyuuid' => $companyuuid, 'stablecode' => $candidate,
                ]);
            }, $minimum);
    }

    public static function save_business_model(string $c,int $u,array $d,string $uuid='',int $rev=0): string {
        global $DB;
        $ownerframeuuid=self::text($d,'ownerframeuuid',36,false);$ownerframerevision=0;$allowed=[];
        $philosophyuuid=self::text($d,'philosophyuuid',36,false);$philosophyrevision=0;
        $marketuuid=self::text($d,'marketuuid',36,false);$marketrevision=0;
        $oldroot=null;
        if($uuid!==''){
            $rows=$DB->get_records('local_kbacompany_bizmodel',['companyuuid'=>$c,'uuid'=>$uuid],'revision DESC','*',0,1);
            if($rows){$oldroot=reset($rows);}
        }
        if($ownerframeuuid!==''){
            if($oldroot && (string)$oldroot->ownerframeuuid===$ownerframeuuid && (int)$oldroot->ownerframerevision>0){
                $set=owner_service::requirement_set($c,$u,$ownerframeuuid,(int)$oldroot->ownerframerevision);
            }else{
                if($oldroot && (string)$oldroot->ownerframeuuid!==$ownerframeuuid &&
                        $DB->record_exists('local_kbacompany_bmcitem',['companyuuid'=>$c,'canvasuuid'=>$uuid,'canvasrevision'=>(int)$oldroot->revision])){
                    self::fail('ownerreq');
                }
                $set=owner_service::requirement_set($c,$u,$ownerframeuuid,0);
            }
            if(!$set)self::fail('ownerreq');$ownerframerevision=(int)$set['revision'];foreach($set['requirements'] as $req){$allowed[]=(string)$req['id'];}
        }else if($oldroot && (string)$oldroot->ownerframeuuid!=='' &&
                $DB->record_exists('local_kbacompany_bmcitem',['companyuuid'=>$c,'canvasuuid'=>$uuid,'canvasrevision'=>(int)$oldroot->revision])){
            self::fail('ownerreq');
        }
        if($philosophyuuid!==''){
            $rows=$DB->get_records('local_kbacompany_philosophy',['companyuuid'=>$c,'uuid'=>$philosophyuuid,'status'=>'published'],'revision DESC','*',0,1);
            if(!$rows)self::fail('reference');$philosophyrevision=(int)reset($rows)->revision;
        }
        if($marketuuid!==''){
            $rows=$DB->get_records('local_kbacompany_market',['companyuuid'=>$c,'uuid'=>$marketuuid,'status'=>'published'],'revision DESC','*',0,1);
            if(!$rows)self::fail('reference');$marketrevision=(int)reset($rows)->revision;
        }
        $canvas=self::canvas_payload($d,$allowed);
        $mapped=$d;
        $mapped['stablecode']=self::business_model_code($c,$uuid);
        $mapped['ownerframeuuid']=$ownerframeuuid;$mapped['ownerframerevision']=(string)$ownerframerevision;
        $mapped['philosophyuuid']=$philosophyuuid;$mapped['philosophyrevision']=(string)$philosophyrevision;
        $mapped['marketuuid']=$marketuuid;$mapped['marketrevision']=(string)$marketrevision;
        $mapped['canvaspayload']=json_encode($canvas,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $mapped['valuecreation']=(string)($canvas['valueprop']['target']!==''?$canvas['valueprop']['target']:$canvas['valueprop']['first']);
        $mapped['channels']=(string)$canvas['channels']['target'];$mapped['relationships']=(string)$canvas['relationships']['target'];
        $mapped['revenue_logic']=(string)$canvas['revenue']['target'];$mapped['cost_logic']=(string)$canvas['costs']['target'];
        $mapped['keyresources']=(string)$canvas['resources']['target'];$mapped['keypartners']=(string)$canvas['partners']['target'];
        $afterinsert=null;
        if($uuid!==''&&$rev>0){
            $afterinsert=static function($row)use($c,$rev){
                business_model_crud_service::clone_snapshot($c,(string)$row->uuid,$rev,(int)$row->revision,'draft');
            };
        }
        return self::save_versioned('local_kbacompany_bizmodel',$c,$u,$mapped,$uuid,$rev,['stablecode'=>[32,false],'name'=>[255,true],'valuecreation'=>[12000,false],'channels'=>[12000,false],'relationships'=>[12000,false],'revenue_logic'=>[12000,false],'cost_logic'=>[12000,false],'keyresources'=>[12000,false],'keypartners'=>[12000,false],'ownerframeuuid'=>[36,false],'ownerframerevision'=>[10,false],'philosophyuuid'=>[36,false],'philosophyrevision'=>[10,false],'marketuuid'=>[36,false],'marketrevision'=>[10,false],'canvaspayload'=>[60000,true]],$afterinsert);
    }
    public static function publish_business_model(string $c,int $u,string $uuid,int $rev):void{
        self::publish_versioned('local_kbacompany_bizmodel',$c,$u,$uuid,$rev,static function($row)use($c,$uuid,$rev){
            business_model_crud_service::clone_snapshot($c,$uuid,$rev,(int)$row->revision,'published');
        });
    }
    public static function archive_business_model(string $c,int $u,string $uuid,int $rev):void{
        // Archiving hides the model from future selection; immutable referenced revisions remain available to history/strategy traceability.
        self::archive_versioned('local_kbacompany_bizmodel',$c,$u,$uuid,$rev,static function($row)use($c,$uuid,$rev){
            business_model_crud_service::clone_snapshot($c,$uuid,$rev,(int)$row->revision,'archived');
        });
    }

    private static function dto(\stdClass $r): array { $a=(array)$r; unset($a['id']);$a['stablecode']=(string)($r->stablecode??'');$canvas=[];if(isset($r->canvaspayload)&&trim((string)$r->canvaspayload)!==''){$decoded=json_decode((string)$r->canvaspayload,true);if(is_array($decoded))$canvas=$decoded;}if(!$canvas){$canvas=['summary'=>'','horizon'=>'','segments'=>[],'valueprop'=>['first'=>(string)($r->valuecreation??''),'current'=>'','data'=>'','hyp'=>'','target'=>(string)($r->valuecreation??''),'criterion'=>'','reqs'=>'','gap'=>'','facilitator'=>''],'relationships'=>['target'=>(string)($r->relationships??'')],'channels'=>['target'=>(string)($r->channels??'')],'activities'=>[],'resources'=>['target'=>(string)($r->keyresources??'')],'partners'=>['target'=>(string)($r->keypartners??'')],'revenue'=>['target'=>(string)($r->revenue_logic??'')],'costs'=>['target'=>(string)($r->cost_logic??'')]];}$a['canvas']=$canvas;return $a; }
    public static function context(string $companyuuid,int $userid): array {
        global $DB; $company=company_context_service::context($companyuuid,$userid);$m=self::can_manage($companyuuid,$userid);
        $markets=array_map([self::class,'dto'],self::current_rows('local_kbacompany_market',$companyuuid,$m));
        $segments=array_map([self::class,'dto'],self::current_rows('local_kbacompany_segment',$companyuuid,$m));
        $offerings=array_map([self::class,'dto'],self::current_rows('local_kbacompany_offering',$companyuuid,$m));
        $models=array_map([self::class,'dto'],self::current_rows('local_kbacompany_bizmodel',$companyuuid,$m));
        $core=[]; foreach(process_service::context($companyuuid,$userid)['processes'] as $p){if($p['type']==='core'&&$p['status']==='published')$core[]=$p;}
        return ['company'=>$company,'markets'=>$markets,'segments'=>$segments,'offerings'=>$offerings,'business_models'=>$models,'core_processes'=>$core,'owner_requirement_sets'=>owner_service::published_requirement_sets($companyuuid,$userid),'published_philosophies'=>company_context_domain_service::published_philosophies($companyuuid,$userid),'published_markets'=>company_context_domain_service::published_markets($companyuuid,$userid),'can_manage'=>$m,'offer_types'=>self::OFFER_TYPES];
    }
}
