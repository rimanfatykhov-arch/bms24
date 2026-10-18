<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Company-scoped 360 feedback cycles that aggregate into formal Assessment Engine evidence. */
class competency_360_service {
    public const CONTRACT_VERSION = '0.7.4';
    private const RELATIONSHIPS = ['self', 'manager', 'peer', 'direct_report', 'other'];
    private const STATUSES = ['draft', 'open', 'closed', 'exported'];

    public static function contract(): array {
        return [
            'component' => 'local_kbacompany',
            'contract' => 'bms_competency_360_assessment',
            'version' => self::CONTRACT_VERSION,
            'company_scope' => 'required_before_fetch',
            'rules' => [
                'cycle_is_company_scoped_and_employee_scoped',
                'respondent_identity_is_not_exposed_in_subject_aggregate',
                'individual_360_response_never_changes_employee_competency_fact',
                'closed_cycle_aggregates_completed_responses_by_competency',
                'aggregate_is_transferred_to_assessment_engine_as_draft_evidence',
                'formal_assessment_approval_is_required_before_employee_profile_projection',
                'course_completion_is_not_360_feedback_and_never_auto_attains_competency',
                'ai_may_assist_wording_but_cannot_submit_response_or_approve_assessment',
            ],
        ];
    }

    public static function relationships(): array { return self::RELATIONSHIPS; }

    public static function pending_for_respondent(string $companyuuid, int $respondentid): array {
        global $DB;
        company_context_service::context($companyuuid, $respondentid);
        $sql = "SELECT r.id, r.cycleuuid, r.relationship, r.status, r.submittedat, c.userid, c.title, c.status AS cyclestatus, c.anonymous, c.openedat\n                  FROM {local_kbacompany_360resp} r\n                  JOIN {local_kbacompany_360cycle} c ON c.uuid=r.cycleuuid AND c.companyuuid=r.companyuuid\n                 WHERE r.companyuuid=:companyuuid AND r.respondentid=:respondentid\n              ORDER BY c.openedat DESC, r.id DESC";
        $rows = $DB->get_records_sql($sql, ['companyuuid'=>$companyuuid,'respondentid'=>$respondentid]);
        $out = [];
        foreach ($rows as $r) {
            $subject = employee_service::employee_context($companyuuid, (int)$r->userid, $respondentid, true);
            $out[] = ['cycleuuid'=>(string)$r->cycleuuid,'title'=>(string)$r->title,'relationship'=>(string)$r->relationship,
                'status'=>(string)$r->status,'cyclestatus'=>(string)$r->cyclestatus,'subject'=>(string)$subject['employee']['full_name'],
                'openedat'=>(int)$r->openedat];
        }
        return $out;
    }

    public static function list_for_employee(string $companyuuid, int $userid, int $actorid): array {
        global $DB;
        company_context_service::context($companyuuid, $actorid);
        employee_service::employee_context($companyuuid, $userid, $actorid, true);
        $rows = $DB->get_records('local_kbacompany_360cycle', ['companyuuid' => $companyuuid, 'userid' => $userid], 'timecreated DESC, id DESC');
        $out = [];
        foreach ($rows as $r) {
            $counts = self::response_counts($companyuuid, (string)$r->uuid);
            $out[] = [
                'uuid' => (string)$r->uuid, 'title' => (string)$r->title, 'status' => (string)$r->status,
                'anonymous' => !empty($r->anonymous), 'minimumresponses' => (int)$r->minimumresponses,
                'respondents' => $counts['total'], 'completed' => $counts['completed'],
                'assessmentuuid' => (string)$r->assessmentuuid, 'timecreated' => (int)$r->timecreated,
            ];
        }
        return $out;
    }

    public static function create(string $companyuuid, int $userid, int $actorid, string $title,
            bool $anonymous = true, int $minimumresponses = 3): string {
        global $DB;
        company_context_service::context($companyuuid, $actorid);
        self::require_manage($companyuuid, $actorid);
        $ctx = employee_competency_profile_service::context($companyuuid, $userid, $actorid);
        if (!$ctx['position_profile']) { throw new \moodle_exception('employeecompetency_no_published_profile', 'local_kbacompany'); }
        $minimumresponses = max(1, min(20, $minimumresponses));
        $uuid = self::uuid(); $now = time();
        $DB->insert_record('local_kbacompany_360cycle', (object)[
            'uuid' => $uuid, 'companyuuid' => $companyuuid, 'userid' => $userid,
            'positionuuid' => (string)($ctx['position']['uuid'] ?? ''),
            'profileuuid' => (string)$ctx['position_profile']['uuid'], 'profilerevision' => (int)$ctx['position_profile']['revision'],
            'title' => trim($title) !== '' ? trim($title) : '360', 'status' => 'draft', 'anonymous' => $anonymous ? 1 : 0,
            'minimumresponses' => $minimumresponses, 'openedat' => 0, 'closedat' => 0, 'assessmentuuid' => '',
            'createdby' => $actorid, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        return $uuid;
    }

    public static function add_respondent(string $companyuuid, string $cycleuuid, int $actorid,
            int $respondentid, string $relationship): void {
        global $DB;
        self::require_manage($companyuuid, $actorid);
        if (!in_array($relationship, self::RELATIONSHIPS, true)) { throw new \invalid_parameter_exception('Invalid 360 relationship'); }
        $cycle = self::cycle_record($companyuuid, $cycleuuid, $actorid);
        if ($cycle->status !== 'draft') { throw new \moodle_exception('competency360_locked', 'local_kbacompany'); }
        employee_service::employee_context($companyuuid, $respondentid, $actorid, false);
        if ($DB->record_exists('local_kbacompany_360resp', ['companyuuid'=>$companyuuid,'cycleuuid'=>$cycleuuid,'respondentid'=>$respondentid])) {
            throw new \moodle_exception('competency360_respondent_exists', 'local_kbacompany');
        }
        $now = time();
        $DB->insert_record('local_kbacompany_360resp', (object)[
            'companyuuid'=>$companyuuid,'cycleuuid'=>$cycleuuid,'respondentid'=>$respondentid,
            'relationship'=>$relationship,'status'=>'invited','submittedat'=>0,'timecreated'=>$now,'timemodified'=>$now,
        ]);
    }

    public static function remove_respondent(string $companyuuid, string $cycleuuid, int $actorid, int $responseid): void {
        global $DB;
        self::require_manage($companyuuid, $actorid);
        $cycle = self::cycle_record($companyuuid, $cycleuuid, $actorid);
        if ($cycle->status !== 'draft') { throw new \moodle_exception('competency360_locked', 'local_kbacompany'); }
        $DB->delete_records('local_kbacompany_360resp', ['id'=>$responseid,'companyuuid'=>$companyuuid,'cycleuuid'=>$cycleuuid]);
    }

    public static function open(string $companyuuid, string $cycleuuid, int $actorid): void {
        global $DB;
        self::require_manage($companyuuid, $actorid);
        $cycle = self::cycle_record($companyuuid, $cycleuuid, $actorid);
        if ($cycle->status !== 'draft') { throw new \moodle_exception('competency360_state', 'local_kbacompany'); }
        $counts = self::response_counts($companyuuid, $cycleuuid);
        if ($counts['total'] < 1) { throw new \moodle_exception('competency360_need_respondent', 'local_kbacompany'); }
        $cycle->status='open'; $cycle->openedat=time(); $cycle->timemodified=time(); $DB->update_record('local_kbacompany_360cycle',$cycle);
    }

    public static function respondent_context(string $companyuuid, string $cycleuuid, int $respondentid): array {
        global $DB;
        company_context_service::context($companyuuid, $respondentid);
        $cycle=$DB->get_record('local_kbacompany_360cycle',['companyuuid'=>$companyuuid,'uuid'=>$cycleuuid],'*',MUST_EXIST);
        $response=$DB->get_record('local_kbacompany_360resp',['companyuuid'=>$companyuuid,'cycleuuid'=>$cycleuuid,'respondentid'=>$respondentid],'*',MUST_EXIST);
        if (!in_array($cycle->status,['open','closed','exported'],true)) { throw new \moodle_exception('competency360_not_open','local_kbacompany'); }
        $subject=employee_service::employee_context($companyuuid,(int)$cycle->userid,$respondentid,true);
        $catalog=[]; foreach(competency_catalog_service::competencies($companyuuid,$respondentid) as $c){$catalog[(int)$c['id']]=$c;}
        $requirements=$DB->get_records('local_kbacompany_rolednacomp',[
            'companyuuid'=>$companyuuid,'rolednauuid'=>(string)$cycle->profileuuid,'rolednarevision'=>(int)$cycle->profilerevision
        ],'id ASC');
        $scores=$DB->get_records('local_kbacompany_360score',['companyuuid'=>$companyuuid,'responseid'=>(int)$response->id],'id ASC');
        $bymap=[]; foreach($scores as $s){$bymap[(int)$s->competencyid]=$s;}
        $items=[];
        foreach($requirements as $req){$cid=(int)$req->competencyid;$s=$bymap[$cid]??null;$items[]=[
            'competencyid'=>$cid,'name'=>$catalog[$cid]['shortname']??('#'.$cid),'targetlevel'=>(int)$req->targetlevel,
            'level'=>$s?(int)$s->level:0,'comment'=>$s?(string)$s->comment:'',
        ];}
        return ['cycle'=>$cycle,'response'=>$response,'subject'=>$subject['employee'],'items'=>$items,
            'can_edit'=>$cycle->status==='open' && $response->status!=='completed'];
    }

    public static function save_response(string $companyuuid, string $cycleuuid, int $respondentid, array $levels, array $comments): void {
        global $DB;
        $ctx=self::respondent_context($companyuuid,$cycleuuid,$respondentid);
        if (!$ctx['can_edit']) { throw new \moodle_exception('competency360_locked','local_kbacompany'); }
        $now=time();
        foreach($ctx['items'] as $item){$cid=(int)$item['competencyid'];$level=(int)($levels[$cid]??0);if($level<0||$level>100){throw new \invalid_parameter_exception('Invalid 360 level');}
            $comment=trim((string)($comments[$cid]??''));
            $existing=$DB->get_record('local_kbacompany_360score',['companyuuid'=>$companyuuid,'responseid'=>(int)$ctx['response']->id,'competencyid'=>$cid]);
            if($existing){$existing->level=$level;$existing->comment=$comment;$existing->timemodified=$now;$DB->update_record('local_kbacompany_360score',$existing);}else{$DB->insert_record('local_kbacompany_360score',(object)[
                'companyuuid'=>$companyuuid,'cycleuuid'=>$cycleuuid,'responseid'=>(int)$ctx['response']->id,'competencyid'=>$cid,'level'=>$level,'comment'=>$comment,'timecreated'=>$now,'timemodified'=>$now,
            ]);}
        }
    }

    public static function submit_response(string $companyuuid, string $cycleuuid, int $respondentid): void {
        global $DB;
        $ctx=self::respondent_context($companyuuid,$cycleuuid,$respondentid);
        if(!$ctx['can_edit']){throw new \moodle_exception('competency360_locked','local_kbacompany');}
        $response=$ctx['response'];$response->status='completed';$response->submittedat=time();$response->timemodified=time();$DB->update_record('local_kbacompany_360resp',$response);
    }

    public static function manager_context(string $companyuuid, string $cycleuuid, int $actorid): array {
        global $DB;
        self::require_manage($companyuuid,$actorid);
        $cycle=self::cycle_record($companyuuid,$cycleuuid,$actorid);
        $subject=employee_service::employee_context($companyuuid,(int)$cycle->userid,$actorid,true);
        $responses=$DB->get_records('local_kbacompany_360resp',['companyuuid'=>$companyuuid,'cycleuuid'=>$cycleuuid],'id ASC');
        $respondents=[];
        foreach($responses as $r){$u=$DB->get_record('user',['id'=>(int)$r->respondentid],'id,firstname,lastname',IGNORE_MISSING);$respondents[]=[
            'id'=>(int)$r->id,'respondentid'=>(int)$r->respondentid,
            'name'=>(!empty($cycle->anonymous) && (int)$cycle->userid===$actorid) ? get_string('competency360_anonymous_respondent','local_kbacompany') : ($u?fullname($u):('#'.(int)$r->respondentid)),
            'relationship'=>(string)$r->relationship,'status'=>(string)$r->status,'submittedat'=>(int)$r->submittedat,
        ];}
        return ['cycle'=>$cycle,'subject'=>$subject['employee'],'respondents'=>$respondents,'counts'=>self::response_counts($companyuuid,$cycleuuid),'aggregate'=>self::aggregate($companyuuid,$cycleuuid,$actorid)];
    }

    public static function close(string $companyuuid, string $cycleuuid, int $actorid): void {
        global $DB;
        self::require_manage($companyuuid,$actorid);
        $cycle=self::cycle_record($companyuuid,$cycleuuid,$actorid);
        if($cycle->status!=='open'){throw new \moodle_exception('competency360_state','local_kbacompany');}
        $counts=self::response_counts($companyuuid,$cycleuuid);
        if($counts['completed']<(int)$cycle->minimumresponses){throw new \moodle_exception('competency360_minimum_not_met','local_kbacompany',(object)['completed'=>$counts['completed'],'minimum'=>(int)$cycle->minimumresponses]);}
        $cycle->status='closed';$cycle->closedat=time();$cycle->timemodified=time();$DB->update_record('local_kbacompany_360cycle',$cycle);
    }

    public static function aggregate(string $companyuuid, string $cycleuuid, int $actorid): array {
        global $DB;
        company_context_service::context($companyuuid,$actorid);
        $cycle=$DB->get_record('local_kbacompany_360cycle',['companyuuid'=>$companyuuid,'uuid'=>$cycleuuid],'*',MUST_EXIST);
        $sql="SELECT s.competencyid, COUNT(1) AS responsecount, AVG(s.level) AS avglevel\n                FROM {local_kbacompany_360score} s\n                JOIN {local_kbacompany_360resp} r ON r.id=s.responseid AND r.companyuuid=s.companyuuid\n               WHERE s.companyuuid=:companyuuid AND s.cycleuuid=:cycleuuid AND r.status='completed'\n            GROUP BY s.competencyid ORDER BY s.competencyid";
        $rows=$DB->get_records_sql($sql,['companyuuid'=>$companyuuid,'cycleuuid'=>$cycleuuid]);
        $catalog=[];foreach(competency_catalog_service::competencies($companyuuid,$actorid) as $c){$catalog[(int)$c['id']]=$c;}
        $out=[];foreach($rows as $r){$cid=(int)$r->competencyid;$out[]=['competencyid'=>$cid,'name'=>$catalog[$cid]['shortname']??('#'.$cid),'responsecount'=>(int)$r->responsecount,'level'=>(int)round((float)$r->avglevel)];}
        return $out;
    }

    public static function export_to_assessment(string $companyuuid, string $cycleuuid, int $actorid): string {
        global $DB;
        self::require_manage($companyuuid,$actorid);
        $cycle=self::cycle_record($companyuuid,$cycleuuid,$actorid);
        if(!in_array($cycle->status,['closed','exported'],true)){throw new \moodle_exception('competency360_state','local_kbacompany');}
        if($cycle->assessmentuuid!==''){return (string)$cycle->assessmentuuid;}
        $aggregate=self::aggregate($companyuuid,$cycleuuid,$actorid);
        $assessmentuuid=competency_assessment_service::create($companyuuid,(int)$cycle->userid,$actorid,'feedback_360');
        $items=$DB->get_records('local_kbacompany_assessitem',['companyuuid'=>$companyuuid,'assessmentuuid'=>$assessmentuuid]);
        $levels=[];foreach($aggregate as $a){$levels[(int)$a['competencyid']]=$a;}
        foreach($items as $item){$cid=(int)$item->competencyid;if(!isset($levels[$cid])){continue;}$item->proposedlevel=(int)$levels[$cid]['level'];$item->evidence='360 aggregate: '.$levels[$cid]['responsecount'].' completed responses';$item->rationale='Aggregated 360 feedback; requires formal Assessment Engine approval.';$item->timemodified=time();$DB->update_record('local_kbacompany_assessitem',$item);}
        $cycle->assessmentuuid=$assessmentuuid;$cycle->status='exported';$cycle->timemodified=time();$DB->update_record('local_kbacompany_360cycle',$cycle);
        return $assessmentuuid;
    }

    private static function response_counts(string $companyuuid, string $cycleuuid): array {
        global $DB;
        return ['total'=>(int)$DB->count_records('local_kbacompany_360resp',['companyuuid'=>$companyuuid,'cycleuuid'=>$cycleuuid]),
            'completed'=>(int)$DB->count_records('local_kbacompany_360resp',['companyuuid'=>$companyuuid,'cycleuuid'=>$cycleuuid,'status'=>'completed'])];
    }

    private static function cycle_record(string $companyuuid, string $cycleuuid, int $actorid) {
        global $DB; company_context_service::context($companyuuid,$actorid);
        return $DB->get_record('local_kbacompany_360cycle',['companyuuid'=>$companyuuid,'uuid'=>$cycleuuid],'*',MUST_EXIST);
    }

    private static function require_manage(string $companyuuid, int $actorid): void {
        company_context_service::context($companyuuid,$actorid);
        if(!permission_service::can_manage_people($companyuuid,$actorid)&&!is_siteadmin($actorid)){
            throw new \required_capability_exception(\context_system::instance(),'local/kbacompany:managepeople','nopermissions','');
        }
    }

    private static function uuid(): string {
        $b=random_bytes(16);$b[6]=chr((ord($b[6])&0x0f)|0x40);$b[8]=chr((ord($b[8])&0x3f)|0x80);$h=bin2hex($b);
        return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20,12);
    }
}
