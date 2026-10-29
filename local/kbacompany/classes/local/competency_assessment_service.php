<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Formal competency assessment workflow and approved projection to the employee competency profile. */
class competency_assessment_service {
    public const CONTRACT_VERSION = '0.7.3';
    private const METHODS = ['manager_observation', 'interview', 'test', 'case_task', 'evidence_review', 'self_assessment', 'feedback_360'];
    private const STATUSES = ['draft', 'submitted', 'approved', 'rejected'];

    public static function contract(): array {
        return [
            'component' => 'local_kbacompany',
            'contract' => 'bms_competency_assessment_engine',
            'version' => self::CONTRACT_VERSION,
            'company_scope' => 'required_before_fetch',
            'rules' => [
                'assessment_is_company_scoped_and_employee_scoped',
                'assessment_uses_published_position_requirements_as_target_context',
                'draft_and_submitted_assessments_do_not_change_employee_competency_fact',
                'approved_assessment_projects_decided_levels_to_employee_competency_profile',
                'course_completion_is_evidence_only_not_automatic_attainment',
                'self_assessment_never_projects_without_approval',
                'approved_assessment_is_immutable',
                'ai_may_propose_but_cannot_approve_or_write_assessment_decisions',
            ],
        ];
    }

    public static function methods(): array { return self::METHODS; }

    public static function list_for_employee(string $companyuuid, int $userid, int $actorid): array {
        global $DB;
        company_context_service::context($companyuuid, $actorid);
        employee_service::employee_context($companyuuid, $userid, $actorid, true);
        $records = $DB->get_records('local_kbacompany_assess', ['companyuuid' => $companyuuid, 'userid' => $userid], 'timecreated DESC, id DESC');
        $out = [];
        foreach ($records as $r) {
            $out[] = [
                'uuid' => (string)$r->uuid, 'method' => (string)$r->method, 'status' => (string)$r->status,
                'assessorid' => (int)$r->assessorid, 'submittedat' => (int)$r->submittedat,
                'decidedby' => (int)$r->decidedby, 'decidedat' => (int)$r->decidedat,
                'decisionnote' => (string)$r->decisionnote, 'timecreated' => (int)$r->timecreated,
            ];
        }
        return $out;
    }

    public static function create(string $companyuuid, int $userid, int $actorid, string $method): string {
        global $DB;
        company_context_service::context($companyuuid, $actorid);
        if (!permission_service::can_manage_people($companyuuid, $actorid) && !is_siteadmin($actorid)) {
            throw new \required_capability_exception(\context_system::instance(), 'local/kbacompany:managepeople', 'nopermissions', '');
        }
        if (!in_array($method, self::METHODS, true)) { throw new \invalid_parameter_exception('Invalid assessment method'); }
        $ctx = employee_competency_profile_service::context($companyuuid, $userid, $actorid);
        if (!$ctx['position_profile']) { throw new \moodle_exception('invalidrecord', 'error'); }
        $uuid = self::uuid(); $now = time();
        $DB->insert_record('local_kbacompany_assess', (object)[
            'uuid'=>$uuid, 'companyuuid'=>$companyuuid, 'userid'=>$userid,
            'positionuuid'=>(string)($ctx['position']['uuid'] ?? ''),
            'profileuuid'=>(string)$ctx['position_profile']['uuid'], 'profilerevision'=>(int)$ctx['position_profile']['revision'],
            'method'=>$method, 'status'=>'draft', 'assessorid'=>$actorid, 'submittedat'=>0,
            'decidedby'=>0, 'decidedat'=>0, 'decisionnote'=>'', 'createdby'=>$actorid,
            'timecreated'=>$now, 'timemodified'=>$now,
        ]);
        foreach ($ctx['requirements'] as $req) {
            $DB->insert_record('local_kbacompany_assessitem', (object)[
                'companyuuid'=>$companyuuid, 'assessmentuuid'=>$uuid, 'competencyid'=>(int)$req['competency_id'],
                'targetlevel'=>(int)($req['target_level'] ?? 0), 'proposedlevel'=>(int)($req['current_level'] ?? 0),
                'decidedlevel'=>0, 'evidence'=>(string)($req['evidence'] ?? ''), 'evidenceurl'=>(string)($req['evidence_url'] ?? ''),
                'rationale'=>'', 'timecreated'=>$now, 'timemodified'=>$now,
            ]);
        }
        return $uuid;
    }

    public static function context(string $companyuuid, string $assessmentuuid, int $actorid): array {
        global $DB;
        company_context_service::context($companyuuid, $actorid);
        $a = $DB->get_record('local_kbacompany_assess', ['companyuuid'=>$companyuuid, 'uuid'=>$assessmentuuid], '*', MUST_EXIST);
        $employee = employee_service::employee_context($companyuuid, (int)$a->userid, $actorid, true);
        $catalog = [];
        foreach (competency_catalog_service::competencies($companyuuid, $actorid) as $c) { $catalog[(int)$c['id']]=$c; }
        $items=[];
        foreach ($DB->get_records('local_kbacompany_assessitem', ['companyuuid'=>$companyuuid,'assessmentuuid'=>$assessmentuuid], 'id ASC') as $i) {
            $cid=(int)$i->competencyid;
            $items[]=['id'=>(int)$i->id,'competencyid'=>$cid,'name'=>$catalog[$cid]['shortname'] ?? ('#'.$cid),
                'targetlevel'=>(int)$i->targetlevel,'proposedlevel'=>(int)$i->proposedlevel,'decidedlevel'=>(int)$i->decidedlevel,
                'evidence'=>(string)$i->evidence,'evidenceurl'=>(string)$i->evidenceurl,'rationale'=>(string)$i->rationale];
        }
        return ['assessment'=>$a,'employee'=>$employee['employee'],'items'=>$items,
            'can_edit'=>$a->status==='draft' && ((int)$a->assessorid===$actorid || permission_service::can_manage_people($companyuuid,$actorid) || is_siteadmin($actorid)),
            'can_decide'=>$a->status==='submitted' && (permission_service::can_manage_people($companyuuid,$actorid) || is_siteadmin($actorid))];
    }

    public static function save_item(string $companyuuid, string $assessmentuuid, int $actorid, int $itemid,
            int $proposedlevel, string $evidence, string $evidenceurl, string $rationale): void {
        global $DB;
        $ctx=self::context($companyuuid,$assessmentuuid,$actorid);
        if (!$ctx['can_edit']) { throw new \moodle_exception('nopermissions','error'); }
        if ($proposedlevel < 0 || $proposedlevel > 100) { throw new \invalid_parameter_exception('Invalid competency level'); }
        $i=$DB->get_record('local_kbacompany_assessitem',['id'=>$itemid,'companyuuid'=>$companyuuid,'assessmentuuid'=>$assessmentuuid],'*',MUST_EXIST);
        $i->proposedlevel=$proposedlevel; $i->evidence=trim($evidence); $i->evidenceurl=trim($evidenceurl); $i->rationale=trim($rationale); $i->timemodified=time();
        $DB->update_record('local_kbacompany_assessitem',$i);
    }

    public static function submit(string $companyuuid, string $assessmentuuid, int $actorid): void {
        global $DB;
        $ctx=self::context($companyuuid,$assessmentuuid,$actorid); $a=$ctx['assessment'];
        if (!$ctx['can_edit']) { throw new \moodle_exception('nopermissions','error'); }
        $a->status='submitted'; $a->submittedat=time(); $a->timemodified=time(); $DB->update_record('local_kbacompany_assess',$a);
    }

    public static function decide(string $companyuuid, string $assessmentuuid, int $actorid, bool $approve, string $note): void {
        global $DB;
        $tx=$DB->start_delegated_transaction();
        $ctx=self::context($companyuuid,$assessmentuuid,$actorid); $a=$ctx['assessment'];
        if (!$ctx['can_decide']) { throw new \moodle_exception('nopermissions','error'); }
        $now=time();
        if ($approve) {
            $items=$DB->get_records('local_kbacompany_assessitem',['companyuuid'=>$companyuuid,'assessmentuuid'=>$assessmentuuid]);
            foreach ($items as $i) {
                $i->decidedlevel=(int)$i->proposedlevel; $i->timemodified=$now; $DB->update_record('local_kbacompany_assessitem',$i);
                employee_competency_profile_service::save_evidence($companyuuid,(int)$a->userid,$actorid,(int)$i->competencyid,(int)$i->decidedlevel,
                    'assessment', trim((string)$i->evidence) !== '' ? (string)$i->evidence : ('Assessment '.$assessmentuuid), (string)$i->evidenceurl);
            }
            $a->status='approved';
        } else { $a->status='rejected'; }
        $a->decidedby=$actorid; $a->decidedat=$now; $a->decisionnote=trim($note); $a->timemodified=$now; $DB->update_record('local_kbacompany_assess',$a);
        $tx->allow_commit();
    }

    private static function uuid(): string {
        $b=random_bytes(16); $b[6]=chr((ord($b[6]) & 0x0f) | 0x40); $b[8]=chr((ord($b[8]) & 0x3f) | 0x80); $h=bin2hex($b);
        return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20,12);
    }
}
