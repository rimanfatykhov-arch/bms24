<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Human-confirmed standardization routing for a completed A3 review. */
class a3_standardization_service {
    public const CONTRACT_VERSION = '0.8.8';
    public const TARGET_KINDS = ['process','function','role','policy','document','indicator','learning'];
    public const STATUSES = ['proposed','confirmed','cancelled'];

    public static function contract(): array {
        return [
            'component'=>'local_kbacompany',
            'contract'=>'bms_a3_standardization_routing',
            'version'=>self::CONTRACT_VERSION,
            'company_scope'=>'required',
            'storage'=>['local_kbacompany_a3std'],
            'rules'=>[
                'only_closed_a3_can_start_standardization',
                'standardization_routes_to_existing_canonical_contours',
                'confirmation_is_human_and_explicit',
                'confirmation_does_not_mutate_target_object',
                'no_parallel_process_policy_document_indicator_learning_engine',
                'all_reads_and_writes_are_company_scoped',
            ],
        ];
    }

    private static function uuid(): string {
        $bytes=random_bytes(16);
        $bytes[6]=chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8]=chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex=bin2hex($bytes);
        return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
    }

    private static function require_manage(string $companyuuid, int $actor): void {
        company_context_service::context($companyuuid,$actor);
        if (!permission_service::can_manage_management($companyuuid,$actor)) {
            throw new \required_capability_exception(\context_system::instance(), permission_service::CAPABILITY_MANAGE, 'nopermissions', '');
        }
    }

    private static function a3(string $companyuuid, string $a3uuid): \stdClass {
        global $DB;
        return $DB->get_record('local_kbacompany_a3review',['companyuuid'=>$companyuuid,'uuid'=>$a3uuid],'*',MUST_EXIST);
    }

    private static function clean(string $value, int $max=0): string {
        $value=trim($value);
        if ($max>0 && \core_text::strlen($value)>$max) { throw new \moodle_exception('a3std_invalid','local_kbacompany'); }
        return $value;
    }

    private static function latest_company_records(string $table, string $companyuuid, string $labelfield): array {
        global $DB;
        $latest=[];
        foreach ($DB->get_records($table,['companyuuid'=>$companyuuid],'revision ASC') as $r) { $latest[(string)$r->uuid]=$r; }
        $out=[];
        foreach ($latest as $r) { $out[(string)$r->uuid]=(string)$r->{$labelfield}; }
        asort($out,SORT_NATURAL|SORT_FLAG_CASE);
        return $out;
    }

    public static function target_options(string $companyuuid, int $actor): array {
        global $DB;
        self::require_manage($companyuuid,$actor);
        $groups=[];
        $groups['process']=self::latest_company_records('local_kbacompany_process',$companyuuid,'name');
        $groups['function']=self::latest_company_records('local_kbacompany_function',$companyuuid,'name');
        $groups['policy']=self::latest_company_records('local_kbacompany_policy',$companyuuid,'title');
        $groups['document']=self::latest_company_records('local_kbacompany_doc',$companyuuid,'title');
        $groups['indicator']=[];
        foreach ($DB->get_records('local_kbacompany_stat',['companyuuid'=>$companyuuid],'name ASC') as $r) {
            $groups['indicator'][(string)$r->uuid]=(string)$r->name;
        }
        $groups['role']=[];
        try {
            $ctx=business_role_service::business_roles_context($companyuuid,$actor);
            foreach ((array)($ctx['roles'] ?? []) as $r) { $groups['role'][(string)$r['uuid']]=(string)$r['name']; }
        } catch (\Throwable $e) { /* Optional cross-plugin projection. */ }
        $groups['learning']=[];
        try {
            foreach (learning_contour_service::company_course_catalogue($companyuuid,$actor) as $course) {
                $id=(int)($course['courseid'] ?? 0);
                if ($id>1) { $groups['learning']['course:'.$id]=(string)($course['fullname'] ?? $course['shortname'] ?? ('#'.$id)); }
            }
        } catch (\Throwable $e) { /* Learning provider may be unavailable. */ }
        return $groups;
    }

    private static function validate_target(string $companyuuid, int $actor, string $kind, string $ref): string {
        global $DB;
        if (!in_array($kind,self::TARGET_KINDS,true)) { throw new \moodle_exception('a3std_invalid','local_kbacompany'); }
        if ($ref==='') { return ''; }
        $maps=[
            'process'=>['local_kbacompany_process','name'], 'function'=>['local_kbacompany_function','name'],
            'policy'=>['local_kbacompany_policy','title'], 'document'=>['local_kbacompany_doc','title'],
            'indicator'=>['local_kbacompany_stat','name'],
        ];
        if (isset($maps[$kind])) {
            [$table,$label]=$maps[$kind];
            $rows=$DB->get_records($table,['companyuuid'=>$companyuuid,'uuid'=>$ref],'revision ASC');
            if (!$rows) { throw new \moodle_exception('a3std_target_invalid','local_kbacompany'); }
            $row=end($rows);
            return (string)$row->{$label};
        }
        $opts=self::target_options($companyuuid,$actor);
        if (!isset($opts[$kind][$ref])) { throw new \moodle_exception('a3std_target_invalid','local_kbacompany'); }
        return (string)$opts[$kind][$ref];
    }

    public static function propose(string $companyuuid, int $actor, string $a3uuid, string $kind, string $ref, string $proposal): string {
        global $DB;
        self::require_manage($companyuuid,$actor);
        $a3=self::a3($companyuuid,$a3uuid);
        if ((string)$a3->status!=='closed') { throw new \moodle_exception('a3std_a3_not_closed','local_kbacompany'); }
        $kind=self::clean($kind,16); $ref=self::clean($ref,64); $proposal=self::clean($proposal);
        if ($proposal==='') { throw new \moodle_exception('a3std_proposal_required','local_kbacompany'); }
        $label=self::validate_target($companyuuid,$actor,$kind,$ref);
        $uuid=self::uuid(); $now=time();
        $DB->insert_record('local_kbacompany_a3std',(object)[
            'uuid'=>$uuid,'companyuuid'=>$companyuuid,'a3uuid'=>$a3uuid,'targetkind'=>$kind,'targetref'=>$ref,
            'targetlabel'=>$label,'proposal'=>$proposal,'status'=>'proposed','createdby'=>$actor,'confirmedby'=>0,
            'timecreated'=>$now,'timeconfirmed'=>0,
        ]);
        return $uuid;
    }

    public static function decide(string $companyuuid, int $actor, string $uuid, string $decision): void {
        global $DB;
        self::require_manage($companyuuid,$actor);
        if (!in_array($decision,['confirmed','cancelled'],true)) { throw new \moodle_exception('a3std_invalid','local_kbacompany'); }
        $row=$DB->get_record('local_kbacompany_a3std',['companyuuid'=>$companyuuid,'uuid'=>$uuid],'*',MUST_EXIST);
        if ((string)$row->status!=='proposed') { throw new \moodle_exception('a3std_already_decided','local_kbacompany'); }
        self::a3($companyuuid,(string)$row->a3uuid);
        if ((string)$row->targetref!=='') { self::validate_target($companyuuid,$actor,(string)$row->targetkind,(string)$row->targetref); }
        $row->status=$decision; $row->confirmedby=$actor; $row->timeconfirmed=time();
        $DB->update_record('local_kbacompany_a3std',$row);
    }

    private static function target_url(string $companyuuid, \stdClass $row): string {
        $kind=(string)$row->targetkind; $ref=(string)$row->targetref;
        if ($ref==='') { return ''; }
        $routes=[
            'process'=>['/local/kbacompany/processes.php','process'],
            'function'=>['/local/kbacompany/functions.php','function'],
            'policy'=>['/local/kbacompany/policies.php','object'],
            'document'=>['/local/kbacompany/documents.php','document'],
            'indicator'=>['/local/kbacompany/indicators.php','stat'],
            'role'=>['/local/kbacompany/business_roles.php','role'],
        ];
        if ($kind==='learning' && strpos($ref,'course:')===0) {
            return (new \moodle_url('/course/view.php',['id'=>(int)substr($ref,7)]))->out(false);
        }
        if (!isset($routes[$kind])) { return ''; }
        return (new \moodle_url($routes[$kind][0],['uuid'=>$companyuuid,$routes[$kind][1]=>$ref]))->out(false);
    }

    public static function context(string $companyuuid, int $actor, string $a3uuid): array {
        global $DB;
        self::require_manage($companyuuid,$actor);
        $a3=self::a3($companyuuid,$a3uuid);
        $rows=[];
        foreach ($DB->get_records('local_kbacompany_a3std',['companyuuid'=>$companyuuid,'a3uuid'=>$a3uuid],'timecreated ASC') as $r) {
            $rows[]=[
                'uuid'=>(string)$r->uuid,'targetkind'=>(string)$r->targetkind,'targetref'=>(string)$r->targetref,
                'targetlabel'=>(string)$r->targetlabel,'proposal'=>(string)$r->proposal,'status'=>(string)$r->status,
                'createdby'=>(int)$r->createdby,'confirmedby'=>(int)$r->confirmedby,'timecreated'=>(int)$r->timecreated,
                'timeconfirmed'=>(int)$r->timeconfirmed,'url'=>self::target_url($companyuuid,$r),
            ];
        }
        return ['a3'=>$a3,'records'=>$rows,'target_options'=>self::target_options($companyuuid,$actor),'contract'=>self::contract()];
    }
}
