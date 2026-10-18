<?php
namespace local_kbacompany\local\task_provider;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\company_context_service;
use local_kbacompany\local\task_provider_service;
use local_kbacompany\local\task_service;

/**
 * Read-only KBA Element learning-task provider.
 *
 * BMS never copies learner submissions or grades. A company explicitly binds Moodle
 * courses to this provider; task status is projected from standard Moodle completion.
 */
class kbaelement_task_provider implements task_provider_interface {
    public function key(): string { return 'kbaelement'; }
    public function label(): string { return get_string('taskprovider_kbaelement', 'local_kbacompany'); }
    public function supports_company_sources(): bool { return true; }

    public function available(): bool {
        global $DB;
        $dbman = $DB->get_manager();
        return $DB->record_exists('modules', ['name' => 'kbaelement']) &&
            $dbman->table_exists(new \xmldb_table('kbaelement'));
    }

    private function user_name(int $userid): string {
        global $DB;
        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], 'id,firstname,lastname');
        return $user ? fullname($user) : '';
    }

    private function bound_course_ids(string $companyuuid): array {
        global $DB;
        $ids = [];
        foreach ($DB->get_records('local_kbacompany_tasksrc', [
            'companyuuid' => $companyuuid, 'provider' => $this->key(), 'active' => 1,
        ]) as $binding) {
            if (preg_match('/^course:(\d+)$/', (string)$binding->sourcekey, $m)) { $ids[(int)$m[1]] = true; }
        }
        return array_keys($ids);
    }

    private function course_authorised(int $courseid, int $userid): bool {
        if ($courseid <= 1) { return false; }
        $context = \context_course::instance($courseid, IGNORE_MISSING);
        if (!$context) { return false; }
        return is_enrolled($context, $userid, '', true) || has_capability('moodle/course:view', $context, $userid);
    }

    /** Course may only be offered as a company source when the binding actor can actually see it. */
    private function course_source_visible(\stdClass $course, int $actor): bool {
        if ((int)$course->id <= 1) { return false; }
        $context = \context_course::instance((int)$course->id, IGNORE_MISSING);
        if (!$context) { return false; }
        if (!is_siteadmin($actor) && !$this->course_authorised((int)$course->id, $actor)) { return false; }
        if (empty($course->visible) && !is_siteadmin($actor) &&
                !has_capability('moodle/course:viewhiddencourses', $context, $actor)) {
            return false;
        }
        return true;
    }

    /** Company employee ids used only for manager's explicit all-tasks projection. */
    private function employee_ids(string $companyuuid, int $actor): array {
        $org = \local_kbacompany\local\organization_service::organization_context($companyuuid, $actor);
        $ids = [];
        foreach ($org['employees'] as $employee) {
            $userid = (int)$employee['user_id'];
            if ($userid > 0) { $ids[$userid] = true; }
        }
        $ids[$actor] = true;
        return array_keys($ids);
    }

    private function text_field(\stdClass $row, array $columns, array $candidates): string {
        foreach ($candidates as $field) {
            if (isset($columns[$field]) && property_exists($row, $field)) {
                $value = trim((string)$row->{$field});
                if ($value !== '') { return strip_tags($value); }
            }
        }
        return '';
    }

    private function due_date(\stdClass $row, array $columns): string {
        foreach (['duedate', 'deadline', 'timeclose'] as $field) {
            if (!isset($columns[$field]) || !property_exists($row, $field)) { continue; }
            $value = $row->{$field};
            if (is_numeric($value) && (int)$value > 0) { return gmdate('Y-m-d', (int)$value); }
            $value = trim((string)$value);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) { return $value; }
        }
        return '';
    }

    private function external_id(int $cmid, int $userid): string { return 'cm:' . $cmid . ':user:' . $userid; }

    private function parse_external_id(string $externalid): ?array {
        if (!preg_match('/^cm:(\d+):user:(\d+)$/', $externalid, $m)) { return null; }
        return [(int)$m[1], (int)$m[2]];
    }

    /** @return array<int,\stdClass> */
    private function module_rows(int $courseid): array {
        global $DB;
        $module = $DB->get_record('modules', ['name' => 'kbaelement'], 'id');
        if (!$module) { return []; }
        $sql = "SELECT cm.id AS cmid, cm.course, cm.instance, cm.visible, k.*
                  FROM {course_modules} cm
                  JOIN {kbaelement} k ON k.id = cm.instance
                 WHERE cm.module = :module AND cm.course = :course AND cm.deletioninprogress = 0
              ORDER BY cm.id ASC";
        return array_values($DB->get_records_sql($sql, ['module' => $module->id, 'course' => $courseid]));
    }

    private function completion(int $cmid, int $userid): bool {
        global $DB;
        $row = $DB->get_record('course_modules_completion', ['coursemoduleid' => $cmid, 'userid' => $userid], 'completionstate');
        return $row && (int)$row->completionstate > 0;
    }

    private function projection(string $companyuuid, int $userid, \stdClass $row, \stdClass $course,
            array $columns): array {
        $complete = $this->completion((int)$row->cmid, $userid);
        $status = $complete ? 'done' : 'open';
        $product = $this->text_field($row, $columns,
            ['expectedresult', 'expected_result', 'product', 'result', 'assignment', 'assignmenttext', 'task']);
        $description = $this->text_field($row, $columns, ['intro', 'description', 'content']);
        $due = $this->due_date($row, $columns);
        return [
            'provider' => $this->key(), 'provider_label' => $this->label(),
            'external_id' => $this->external_id((int)$row->cmid, $userid), 'canonical_task_uuid' => '',
            'source_kind' => 'learning', 'source_label' => format_string($course->fullname), 'stream' => 'develop',
            'title' => format_string((string)($row->name ?? get_string('taskprovider_kbaelement', 'local_kbacompany'))),
            'description' => $description, 'product' => $product,
            'assignee_id' => $userid, 'assignee_name' => $this->user_name($userid),
            'creator_id' => 0, 'creator_name' => '', 'reviewer_id' => 0, 'reviewer_name' => '',
            'priority' => 'normal', 'status' => $status, 'start_date' => '', 'due_date' => $due,
            'plan_minutes' => 0, 'fact_minutes' => 0, 'result' => '', 'revision' => 0,
            'started_at' => 0, 'completed_at' => 0, 'time_created' => (int)($row->timecreated ?? 0),
            'time_modified' => (int)($row->timemodified ?? 0), 'editable' => false,
            'progress' => $complete ? 100 : 0,
            'source_url' => (new \moodle_url('/mod/kbaelement/view.php', ['id' => (int)$row->cmid]))->out(false),
            'course_id' => (int)$course->id, 'course_name' => format_string($course->fullname),
            'cmid' => (int)$row->cmid,
        ];
    }

    public function list_tasks(string $companyuuid, int $actor, int $targetuserid, string $scope = 'my'): array {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        if (!$this->available()) { return []; }
        $canmanage = task_service::can_manage($companyuuid, $actor);
        if ($targetuserid < 0) { $targetuserid = 0; }
        if ($targetuserid > 0 && $targetuserid !== $actor && !$canmanage) { return []; }
        if ($targetuserid === 0 && !($scope === 'all' && $canmanage)) { $targetuserid = $actor; }
        $targetusers = $targetuserid > 0 ? [$targetuserid] : $this->employee_ids($companyuuid, $actor);
        $columns = $DB->get_columns('kbaelement');
        $today = gmdate('Y-m-d'); $result = [];
        foreach ($this->bound_course_ids($companyuuid) as $courseid) {
            $course = $DB->get_record('course', ['id' => $courseid], 'id,fullname,shortname,visible');
            if (!$course) { continue; }
            foreach ($targetusers as $userid) {
                if (!$this->course_authorised($courseid, (int)$userid)) { continue; }
                // A hidden bound course is still a valid company source, but the employee must be able to access it.
                if (empty($course->visible)) {
                    $coursecontext = \context_course::instance($courseid, IGNORE_MISSING);
                    if (!$coursecontext || (!is_siteadmin((int)$userid) &&
                            !has_capability('moodle/course:viewhiddencourses', $coursecontext, (int)$userid))) {
                        continue;
                    }
                }
                foreach ($this->module_rows($courseid) as $row) {
                    $modulecontext = \context_module::instance((int)$row->cmid, IGNORE_MISSING);
                    if (!$modulecontext) { continue; }
                    if (empty($row->visible) && !is_siteadmin((int)$userid) &&
                            !has_capability('moodle/course:viewhiddenactivities', $modulecontext, (int)$userid)) { continue; }
                    $task = $this->projection($companyuuid, (int)$userid, $row, $course, $columns);
                    if ($scope === 'created' || $scope === 'review') { continue; }
                    if ($scope === 'overdue') {
                        if ($task['due_date'] === '' || $task['due_date'] >= $today ||
                                in_array($task['status'], ['done', 'cancelled'], true)) { continue; }
                    }
                    $result[] = $task;
                }
            }
        }
        return $result;
    }

    public function get_task(string $companyuuid, int $actor, string $externalid): ?array {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        if (!$this->available()) { return null; }
        $parsed = $this->parse_external_id($externalid);
        if (!$parsed) { return null; }
        [$cmid, $userid] = $parsed;
        if ($userid !== $actor && !task_service::can_manage($companyuuid, $actor)) { return null; }
        $cm = $DB->get_record('course_modules', ['id' => $cmid], 'id,course,module,instance,visible,deletioninprogress');
        if (!$cm || !empty($cm->deletioninprogress) || !in_array((int)$cm->course, $this->bound_course_ids($companyuuid), true)) { return null; }
        if (!$this->course_authorised((int)$cm->course, $userid)) { return null; }
        $module = $DB->get_record('modules', ['id' => $cm->module, 'name' => 'kbaelement'], 'id');
        $row = $DB->get_record('kbaelement', ['id' => $cm->instance]);
        $course = $DB->get_record('course', ['id' => $cm->course], 'id,fullname,shortname,visible');
        if (!$module || !$row || !$course) { return null; }
        $coursecontext = \context_course::instance((int)$cm->course, IGNORE_MISSING);
        if (!$coursecontext) { return null; }
        if (empty($course->visible) && !is_siteadmin($userid) &&
                !has_capability('moodle/course:viewhiddencourses', $coursecontext, $userid)) { return null; }
        $modulecontext = \context_module::instance($cmid, IGNORE_MISSING);
        if (!$modulecontext) { return null; }
        if (empty($cm->visible) && !is_siteadmin($userid) &&
                !has_capability('moodle/course:viewhiddenactivities', $modulecontext, $userid)) { return null; }
        $row->cmid = $cmid; $row->course = $cm->course; $row->visible = $cm->visible;
        return $this->projection($companyuuid, $userid, $row, $course, $DB->get_columns('kbaelement'));
    }

    public function available_sources(string $companyuuid, int $actor): array {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        if (!$this->available()) { return []; }
        $module = $DB->get_record('modules', ['name' => 'kbaelement'], 'id');
        if (!$module) { return []; }
        // Only courses already explicitly linked to this company, or containing an active
        // enrolment of its active member, are candidates. Never scan the global course catalog.
        // Existing course visibility/capability checks still apply; this does not enrol anyone.
        $sourcekey = $DB->sql_concat("'course:'", 'c.id');
        $sql = "SELECT DISTINCT c.id, c.fullname, c.shortname, c.visible
                  FROM {course} c
                  JOIN {course_modules} cm ON cm.course = c.id
                 WHERE cm.module = :module AND cm.deletioninprogress = 0 AND c.id > 1
                   AND (EXISTS (SELECT s.id FROM {local_kbacompany_tasksrc} s
                         WHERE s.companyuuid=:bindingcompany AND s.provider='kbaelement' AND s.sourcekey=$sourcekey)
                     OR EXISTS (SELECT ue.id FROM {user_enrolments} ue
                         JOIN {enrol} en ON en.id=ue.enrolid
                         JOIN {local_kbacompany_member} m ON m.userid=ue.userid AND m.companyuuid=:membercompany AND m.active=1
                         JOIN {user} u ON u.id=m.userid AND u.deleted=0 AND u.suspended=0
                        WHERE en.courseid=c.id AND en.status=0 AND ue.status=0
                          AND ue.timestart<=:enrolstart AND (ue.timeend=0 OR ue.timeend>:enrolend)))
              ORDER BY c.fullname ASC";
        $sources = [];
        $params = ['module'=>$module->id, 'bindingcompany'=>$companyuuid, 'membercompany'=>$companyuuid,
            'enrolstart'=>time(), 'enrolend'=>time()];
        foreach ($DB->get_records_sql($sql, $params, 0, 100) as $course) {
            if (!$this->course_source_visible($course, $actor)) { continue; }
            $sources[] = ['source_key' => 'course:' . (int)$course->id,
                'label' => format_string($course->fullname), 'course_id' => (int)$course->id];
        }
        return $sources;
    }
}
