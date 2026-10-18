<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Individual development plans derived from confirmed competency gaps. */
class development_plan_service {
    public const CONTRACT_VERSION = '0.7.6';
    private const PLAN_STATUSES = ['draft', 'active', 'completed', 'cancelled'];
    private const ITEM_STATUSES = ['planned', 'in_progress', 'completed', 'cancelled'];
    private const ITEM_TYPES = ['learning', 'practice', 'mentoring', 'project', 'reading', 'other'];

    public static function contract(): array {
        return [
            'component' => 'local_kbacompany',
            'contract' => 'bms_employee_development_plan',
            'version' => self::CONTRACT_VERSION,
            'company_scope' => 'required_before_fetch',
            'rules' => [
                'development_plan_is_company_scoped_and_employee_scoped',
                'confirmed_competency_gap_is_input_not_automatic_assignment',
                'learning_execution_remains_owned_by_moodle_and_kba_corporate',
                'course_completion_does_not_automatically_close_competency_gap',
                'development_item_completion_does_not_change_employee_competency_fact',
                'competency_attainment_requires_assessment_engine_approval',
                'ai_may_propose_plan_actions_but_cannot_activate_or_complete_plan',
                'company_course_linkage_is_validated_against_learning_contour_catalogue',
                'moodle_learning_status_is_read_live_and_never_written_as_competency_fact',
            ],
        ];
    }

    public static function item_types(): array { return self::ITEM_TYPES; }

    public static function list_for_employee(string $companyuuid, int $userid, int $actorid): array {
        global $DB;
        company_context_service::context($companyuuid, $actorid);
        employee_service::employee_context($companyuuid, $userid, $actorid, true);
        return array_values($DB->get_records('local_kbacompany_devplan', [
            'companyuuid' => $companyuuid, 'userid' => $userid,
        ], 'timecreated DESC, id DESC'));
    }

    public static function create_from_confirmed_gaps(string $companyuuid, int $userid, int $actorid, string $title,
            int $targetdate = 0): string {
        global $DB;
        self::require_manage($companyuuid, $actorid);
        $profile = employee_competency_profile_service::context($companyuuid, $userid, $actorid);
        if (!$profile['position_profile']) {
            throw new \moodle_exception('invalidrecord', 'error');
        }
        $gaps = [];
        foreach ($profile['requirements'] as $row) {
            if ($row['state'] === 'gap' && $row['source'] === 'assessment') {
                $gaps[] = $row;
            }
        }
        if (!$gaps) {
            throw new \moodle_exception('developmentplan_no_confirmed_gaps', 'local_kbacompany');
        }
        $now = time();
        $uuid = self::uuid();
        $DB->insert_record('local_kbacompany_devplan', (object)[
            'uuid' => $uuid, 'companyuuid' => $companyuuid, 'userid' => $userid,
            'positionuuid' => (string)($profile['position']['uuid'] ?? ''),
            'profileuuid' => (string)$profile['position_profile']['uuid'],
            'profilerevision' => (int)$profile['position_profile']['revision'],
            'title' => trim($title) !== '' ? trim($title) : get_string('developmentplan_default_title', 'local_kbacompany'),
            'status' => 'draft', 'targetdate' => $targetdate, 'activatedat' => 0, 'completedat' => 0,
            'createdby' => $actorid, 'modifiedby' => $actorid, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        foreach ($gaps as $row) {
            $DB->insert_record('local_kbacompany_devitem', (object)[
                'uuid' => self::uuid(), 'companyuuid' => $companyuuid, 'planuuid' => $uuid,
                'competencyid' => (int)$row['competency_id'], 'itemtype' => 'practice',
                'title' => get_string('developmentplan_gap_action_default', 'local_kbacompany', $row['name']),
                'description' => '', 'targetlevel' => (int)$row['target_level'],
                'baselinelevel' => (int)$row['current_level'], 'status' => 'planned', 'duedate' => $targetdate,
                'responsibleid' => $userid, 'courseid' => 0, 'learningurl' => '', 'evidence' => '',
                'createdby' => $actorid, 'modifiedby' => $actorid, 'timecreated' => $now, 'timemodified' => $now,
            ]);
        }
        return $uuid;
    }

    public static function context(string $companyuuid, string $planuuid, int $actorid): array {
        global $DB;
        company_context_service::context($companyuuid, $actorid);
        $plan = $DB->get_record('local_kbacompany_devplan', ['companyuuid' => $companyuuid, 'uuid' => $planuuid], '*', MUST_EXIST);
        $employee = employee_service::employee_context($companyuuid, (int)$plan->userid, $actorid, true);
        $catalog = [];
        foreach (competency_catalog_service::competencies($companyuuid, $actorid) as $c) { $catalog[(int)$c['id']] = $c; }
        $learningcatalogue = [];
        try {
            foreach (learning_contour_service::company_course_catalogue($companyuuid, $actorid) as $course) {
                $courseid = (int)($course['courseid'] ?? 0);
                if ($courseid > 1) {
                    $learningcatalogue[$courseid] = $course;
                }
            }
        } catch (\Throwable $e) {
            $learningcatalogue = [];
        }
        $items = [];
        foreach ($DB->get_records('local_kbacompany_devitem', ['companyuuid' => $companyuuid, 'planuuid' => $planuuid], 'id ASC') as $i) {
            $cid = (int)$i->competencyid;
            $learning = null;
            if (!empty($i->courseid)) {
                try {
                    $learning = learning_contour_service::course_status_for_employee(
                        $companyuuid, $actorid, (int)$plan->userid, (int)$i->courseid
                    );
                } catch (\Throwable $e) {
                    $learning = [
                        'course_id' => (int)$i->courseid,
                        'name' => '',
                        'url' => (string)$i->learningurl,
                        'enrolled' => false,
                        'completed' => false,
                        'status' => 'unavailable',
                    ];
                }
            }
            $items[] = [
                'record' => $i,
                'competency' => $catalog[$cid]['shortname'] ?? ('#' . $cid),
                'learning' => $learning,
            ];
        }
        $canmanage = permission_service::can_manage_people($companyuuid, $actorid) || is_siteadmin($actorid);
        return [
            'plan' => $plan,
            'employee' => $employee['employee'],
            'items' => $items,
            'can_manage' => $canmanage,
            'learning_catalogue' => array_values($learningcatalogue),
        ];
    }

    public static function save_item(string $companyuuid, string $planuuid, int $actorid, int $itemid, string $itemtype,
            string $title, string $description, int $duedate, int $responsibleid, int $courseid, string $learningurl, string $status,
            string $evidence): void {
        global $DB;
        self::require_manage($companyuuid, $actorid);
        $plan = $DB->get_record('local_kbacompany_devplan', ['companyuuid' => $companyuuid, 'uuid' => $planuuid], '*', MUST_EXIST);
        if (in_array($plan->status, ['completed', 'cancelled'], true)) { throw new \moodle_exception('nopermissions', 'error'); }
        if (!in_array($itemtype, self::ITEM_TYPES, true) || !in_array($status, self::ITEM_STATUSES, true)) {
            throw new \invalid_parameter_exception('Invalid development item state');
        }
        $item = $DB->get_record('local_kbacompany_devitem', ['id' => $itemid, 'companyuuid' => $companyuuid, 'planuuid' => $planuuid], '*', MUST_EXIST);
        employee_service::employee_context($companyuuid, $responsibleid, $actorid, true);
        if ($courseid > 0) {
            $course = learning_contour_service::course_status_for_employee($companyuuid, $actorid, (int)$plan->userid, $courseid);
            if (trim($learningurl) === '') {
                $learningurl = (string)$course['url'];
            }
        }
        $item->itemtype = $itemtype; $item->title = trim($title); $item->description = trim($description);
        $item->duedate = $duedate; $item->responsibleid = $responsibleid; $item->courseid = max(0, $courseid); $item->learningurl = trim($learningurl);
        $item->status = $status; $item->evidence = trim($evidence); $item->modifiedby = $actorid; $item->timemodified = time();
        $DB->update_record('local_kbacompany_devitem', $item);
    }

    public static function transition(string $companyuuid, string $planuuid, int $actorid, string $target): void {
        global $DB;
        self::require_manage($companyuuid, $actorid);
        if (!in_array($target, self::PLAN_STATUSES, true)) { throw new \invalid_parameter_exception('Invalid plan status'); }
        $plan = $DB->get_record('local_kbacompany_devplan', ['companyuuid' => $companyuuid, 'uuid' => $planuuid], '*', MUST_EXIST);
        $allowed = [
            'draft' => ['active', 'cancelled'],
            'active' => ['completed', 'cancelled'],
            'completed' => [], 'cancelled' => [],
        ];
        if (!in_array($target, $allowed[$plan->status] ?? [], true)) { throw new \moodle_exception('nopermissions', 'error'); }
        $now = time(); $plan->status = $target; $plan->modifiedby = $actorid; $plan->timemodified = $now;
        if ($target === 'active') { $plan->activatedat = $now; }
        if ($target === 'completed') { $plan->completedat = $now; }
        $DB->update_record('local_kbacompany_devplan', $plan);
    }

    private static function require_manage(string $companyuuid, int $actorid): void {
        company_context_service::context($companyuuid, $actorid);
        if (!permission_service::can_manage_people($companyuuid, $actorid) && !is_siteadmin($actorid)) {
            throw new \required_capability_exception(\context_system::instance(), 'local/kbacompany:managepeople', 'nopermissions', '');
        }
    }

    private static function uuid(): string {
        $b = random_bytes(16); $b[6] = chr((ord($b[6]) & 0x0f) | 0x40); $b[8] = chr((ord($b[8]) & 0x3f) | 0x80); $h = bin2hex($b);
        return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20,12);
    }
}
