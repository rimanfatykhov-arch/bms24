<?php
namespace local_kbacompany;

use core_competency\api;
use core_competency\course_module_competency;
use core_competency\user_competency;
use local_kbacompany\local\competency_review_queue_service;

final class competency_review_queue_test extends \advanced_testcase {
    public function test_published_work_grade_is_separate_and_rechecks_access(): void {
        global $CFG, $DB;
        [$competencyid, $roleid] = $this->fixture();
        require_once($CFG->libdir . '/gradelib.php');
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1, 'showgrades' => 1]);
        $employee = $generator->create_and_enrol($course, 'student');
        $reviewer = $generator->create_and_enrol($course, 'teacher');
        role_assign($roleid, $reviewer->id, \context_user::instance($employee->id)->id);
        api::add_competency_to_course($course->id, $competencyid);
        $assignment = $this->activity($competencyid, $course);
        $this->submit($assignment, $employee);
        $request = $this->request_id($employee, $competencyid);
        $this->setUser($reviewer);
        $this->assertNull(competency_review_queue_service::work_grade($request));
        grade_update('test', $course->id, 'mod', 'assign', $assignment->get_instance()->id, 0,
            ['userid' => $employee->id, 'rawgrade' => 85]);
        grade_regrade_final_grades($course->id);
        $this->assertSame('85.00', competency_review_queue_service::work_grade($request));
        $this->assertSame('85.00', competency_review_queue_service::queue()['rows'][0]['workgrade']);
        $this->assertEquals(user_competency::STATUS_WAITING_FOR_REVIEW,
            $DB->get_field('competency_usercomp', 'status', ['id' => $request]));
        $item = \grade_item::fetch(['courseid' => $course->id, 'itemmodule' => 'assign',
            'iteminstance' => $assignment->get_instance()->id]);
        $item->set_hidden(1);
        $this->assertNull(competency_review_queue_service::work_grade($request));
        $item->set_hidden(0);
        $grade = \grade_grade::fetch(['itemid' => $item->id, 'userid' => $employee->id]);
        $grade->set_hidden(1);
        $this->assertNull(competency_review_queue_service::work_grade($request));
        $grade->set_hidden(time() + 3600);
        $this->assertNull(competency_review_queue_service::work_grade($request));
        $grade->set_hidden(0);
        $item->set_hidden(time() + 3600);
        $this->assertNull(competency_review_queue_service::work_grade($request));
        $item->set_hidden(0);
        $this->assertSame('85.00', competency_review_queue_service::work_grade($request));

        $DB->set_field('course', 'showgrades', 0, ['id' => $course->id]);
        rebuild_course_cache($course->id, true);
        $this->assertNull(competency_review_queue_service::work_grade($request));
        $DB->set_field('course', 'showgrades', 1, ['id' => $course->id]);
        rebuild_course_cache($course->id, true);
        $studentrole = $DB->get_record('role', ['shortname' => 'student'], '*', MUST_EXIST);
        assign_capability('moodle/grade:view', CAP_PROHIBIT, $studentrole->id,
            \context_course::instance($course->id)->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertNull(competency_review_queue_service::work_grade($request));
        unassign_capability('moodle/grade:view', $studentrole->id, \context_course::instance($course->id)->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertSame('85.00', competency_review_queue_service::work_grade($request));

        $DB->set_field('assign', 'markingworkflow', 1, ['id' => $assignment->get_instance()->id]);
        $this->assertNull(competency_review_queue_service::work_grade($request));
        $DB->insert_record('assign_user_flags', ['assignment' => $assignment->get_instance()->id,
            'userid' => $employee->id, 'workflowstate' => ASSIGN_MARKING_WORKFLOW_STATE_RELEASED]);
        $this->assertSame('85.00', competency_review_queue_service::work_grade($request));
        grade_update('test', $course->id, 'mod', 'assign', $assignment->get_instance()->id, 0,
            ['userid' => $employee->id, 'rawgrade' => 0]);
        grade_regrade_final_grades($course->id);
        $this->assertSame('0.00', competency_review_queue_service::work_grade($request));
        $before = $DB->get_record('grade_grades', ['itemid' => $item->id, 'userid' => $employee->id]);
        $DB->set_field('grade_items', 'needsupdate', 1, ['id' => $item->id]);
        $this->assertNull(competency_review_queue_service::work_grade($request));
        $this->assertEquals(1, $DB->get_field('grade_items', 'needsupdate', ['id' => $item->id]));
        $this->assertEquals($before, $DB->get_record('grade_grades', ['itemid' => $item->id, 'userid' => $employee->id]));
        $DB->set_field('grade_items', 'needsupdate', 0, ['id' => $item->id]);
        $this->setUser($employee);
        $this->assertNull(competency_review_queue_service::work_grade($request));
        $this->setUser($reviewer);
        role_unassign($roleid, $reviewer->id, \context_user::instance($employee->id)->id);
        $this->assertNull(competency_review_queue_service::work_grade($request));
    }

    private function fixture(): array {
        global $CFG;
        $this->resetAfterTest();
        $this->setAdminUser();
        require_once($CFG->libdir . '/completionlib.php');
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        set_config('enabled', 1, 'core_competency');
        set_config('enablecompletion', 1);
        $generator = $this->getDataGenerator();
        $competencies = $generator->get_plugin_generator('core_competency');
        $framework = $competencies->create_framework();
        $competency = $competencies->create_competency(['competencyframeworkid' => $framework->get('id')]);
        $roleid = $generator->create_role();
        foreach (['moodle/competency:usercompetencyreview', 'moodle/competency:usercompetencyview'] as $capability) {
            assign_capability($capability, CAP_ALLOW, $roleid, \context_system::instance()->id);
        }
        return [(int)$competency->get('id'), $roleid];
    }

    private function activity(int $competencyid, \stdClass $course, array $settings = []): \assign {
        global $DB;
        $module = $this->getDataGenerator()->create_module('assign', $settings + [
            'course' => $course->id,
            'assignsubmission_onlinetext_enabled' => 1,
            'assignsubmission_file_enabled' => 0,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionsubmit' => 1,
            'submissiondrafts' => 0,
        ]);
        api::add_competency_to_course_module($module->cmid, $competencyid);
        $link = $DB->get_record(course_module_competency::TABLE, ['cmid' => $module->cmid,
            'competencyid' => $competencyid], '*', MUST_EXIST);
        api::set_course_module_competency_ruleoutcome($link->id, course_module_competency::OUTCOME_RECOMMEND);
        $cm = get_coursemodule_from_id('assign', $module->cmid, 0, false, MUST_EXIST);
        return new \assign(\context_module::instance($cm->id), $cm, $course);
    }

    private function submit(\assign $assignment, \stdClass $employee): void {
        $this->setUser($employee);
        $data = (object)['onlinetext_editor' => [
            'text' => 'Production plan', 'format' => FORMAT_HTML, 'itemid' => file_get_unused_draft_itemid(),
        ]];
        $notices = [];
        $this->assertTrue($assignment->save_submission($data, $notices));
        $this->assertSame([], $notices);
    }

    private function company_membership(string $company, string $membership, \stdClass $employee): void {
        global $DB;
        $DB->insert_record('local_kbacompany_company', ['uuid' => $company, 'name' => $company,
            'sourcekind' => 'bms', 'bmsclient' => 1]);
        $DB->insert_record('local_kbacompany_member', ['uuid' => $membership, 'companyuuid' => $company,
            'userid' => $employee->id]);
    }

    private function request_id(\stdClass $employee, int $competencyid): int {
        return (int)user_competency::get_record(['userid' => $employee->id, 'competencyid' => $competencyid])->get('id');
    }

    public function test_queue_and_link_follow_target_moodle_permissions_across_companies(): void {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        [$competencyid, $roleid] = $this->fixture();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1, 'showgrades' => 1]);
        $employee = $generator->create_and_enrol($course, 'student');
        $otheremployee = $generator->create_and_enrol($course, 'student');
        $reviewer = $generator->create_and_enrol($course, 'teacher');
        $withoutgrading = $generator->create_and_enrol($course, 'student');
        $this->company_membership('11111111-1111-4111-8111-111111111111', '33333333-3333-4333-8333-333333333333', $employee);
        $this->company_membership('22222222-2222-4222-8222-222222222222', '44444444-4444-4444-8444-444444444444', $otheremployee);
        $usercontext = \context_user::instance($employee->id);
        role_assign($roleid, $reviewer->id, $usercontext->id);
        role_assign($roleid, $withoutgrading->id, $usercontext->id);
        api::add_competency_to_course($course->id, $competencyid);
        $assignment = $this->activity($competencyid, $course);
        $this->submit($assignment, $employee);
        $this->submit($assignment, $otheremployee);
        $request = $this->request_id($employee, $competencyid);
        $otherrequest = $this->request_id($otheremployee, $competencyid);

        foreach ([$employee, $otheremployee] as $learner) {
            grade_update('test', $course->id, 'mod', 'assign', $assignment->get_instance()->id, 0,
                ['userid' => $learner->id, 'rawgrade' => 85]);
        }
        grade_regrade_final_grades($course->id);
        $this->setUser($reviewer);
        $queue = competency_review_queue_service::queue();
        $this->assertSame(1, $queue['count']);
        $this->assertSame([$request], array_column($queue['rows'], 'id'));
        $url = competency_review_queue_service::work_url($request);
        $this->assertInstanceOf(\moodle_url::class, $url);
        $this->assertSame((new \moodle_url('/mod/assign/view.php'))->get_path(), $url->get_path());
        $this->assertSame((string)$assignment->get_course_module()->id, (string)$url->get_param('id'));
        $this->assertSame((string)$employee->id, (string)$url->get_param('userid'));
        $this->assertSame('grader', $url->get_param('action'));
        $this->assertSame($url->out(false), $queue['rows'][0]['workurl']->out(false));
        $this->assertNull(competency_review_queue_service::work_url($otherrequest));
        $this->assertSame('85.00', competency_review_queue_service::work_grade($request));
        $this->assertNull(competency_review_queue_service::work_grade($otherrequest));
        // Personal Moodle queue: explicit target rights, not selected-company filtering.
        $othercontext = \context_user::instance($otheremployee->id);
        role_assign($roleid, $reviewer->id, $othercontext->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertSame(2, competency_review_queue_service::queue()['count']);
        $this->assertSame('85.00', competency_review_queue_service::work_grade($otherrequest));
        role_unassign($roleid, $reviewer->id, $othercontext->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertSame(1, competency_review_queue_service::queue()['count']);
        $this->assertNull(competency_review_queue_service::work_url($otherrequest));
        $this->assertNull(competency_review_queue_service::work_grade($otherrequest));

        $this->setUser($withoutgrading);
        $this->assertSame(1, competency_review_queue_service::queue()['count']);
        $this->assertNull(competency_review_queue_service::work_url($request));
        $this->setUser($employee);
        $this->assertSame(0, competency_review_queue_service::queue()['count']);
        $this->assertNull(competency_review_queue_service::work_url($request));
        $this->setGuestUser();
        $this->assertFalse(competency_review_queue_service::has_pending());
        $this->assertSame(0, competency_review_queue_service::queue()['count']);
        $this->assertNull(competency_review_queue_service::work_url($request));
    }

    public function test_other_reviewers_work_and_closed_review_are_not_linked(): void {
        [$competencyid, $roleid] = $this->fixture();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $employee = $generator->create_and_enrol($course, 'student');
        $reviewer = $generator->create_and_enrol($course, 'teacher');
        $otherreviewer = $generator->create_and_enrol($course, 'teacher');
        foreach ([$reviewer, $otherreviewer] as $actor) {
            role_assign($roleid, $actor->id, \context_user::instance($employee->id)->id);
        }
        api::add_competency_to_course($course->id, $competencyid);
        $assignment = $this->activity($competencyid, $course);
        $this->submit($assignment, $employee);
        $request = $this->request_id($employee, $competencyid);
        $this->setUser($otherreviewer);
        $this->assertTrue(api::user_competency_start_review($employee->id, $competencyid));
        $this->assertInstanceOf(\moodle_url::class, competency_review_queue_service::work_url($request));

        $this->setUser($reviewer);
        $this->assertSame(0, competency_review_queue_service::queue()['count']);
        $this->assertNull(competency_review_queue_service::work_url($request));

        $this->setUser($otherreviewer);
        $this->assertTrue(api::user_competency_stop_review($employee->id, $competencyid));
        $this->assertSame(0, competency_review_queue_service::queue()['count']);
        $this->assertNull(competency_review_queue_service::work_url($request));
    }

    public function test_missing_draft_and_ambiguous_evidence_fall_back_to_review(): void {
        global $DB;
        [$competencyid, $roleid] = $this->fixture();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $employee = $generator->create_and_enrol($course, 'student');
        $reviewer = $generator->create_and_enrol($course, 'teacher');
        role_assign($roleid, $reviewer->id, \context_user::instance($employee->id)->id);
        api::add_competency_to_course($course->id, $competencyid);
        $first = $this->activity($competencyid, $course);
        $second = $this->activity($competencyid, $course);
        $this->setUser($employee);
        $this->assertTrue(api::user_competency_request_review($employee->id, $competencyid));
        $request = $this->request_id($employee, $competencyid);

        $this->setUser($reviewer);
        $this->assertNull(competency_review_queue_service::work_url($request));
        $this->assertInstanceOf(\moodle_url::class, competency_review_queue_service::queue()['rows'][0]['reviewurl']);
        $this->submit($first, $employee);
        $this->setUser($reviewer);
        $this->assertInstanceOf(\moodle_url::class, competency_review_queue_service::work_url($request));

        // Simulate a submission returned to draft after the evidence was recorded.
        $submission = $first->get_user_submission($employee->id, false);
        $DB->set_field('assign_submission', 'status', ASSIGN_SUBMISSION_STATUS_DRAFT, ['id' => $submission->id]);
        $this->assertNull(competency_review_queue_service::work_url($request));
        $DB->set_field('assign_submission', 'status', ASSIGN_SUBMISSION_STATUS_SUBMITTED, ['id' => $submission->id]);

        $this->submit($second, $employee);
        $this->setUser($reviewer);
        $this->assertNull(competency_review_queue_service::work_url($request));
    }

    public function test_blind_marking_and_evidence_older_than_submission_hide_work_link(): void {
        global $DB;
        [$competencyid, $roleid] = $this->fixture();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $employee = $generator->create_and_enrol($course, 'student');
        $reviewer = $generator->create_and_enrol($course, 'teacher');
        role_assign($roleid, $reviewer->id, \context_user::instance($employee->id)->id);
        api::add_competency_to_course($course->id, $competencyid);
        $assignment = $this->activity($competencyid, $course, ['blindmarking' => 1]);
        $this->submit($assignment, $employee);
        $request = $this->request_id($employee, $competencyid);
        $this->setUser($reviewer);
        $this->assertNull(competency_review_queue_service::work_url($request));

        $submission = $assignment->get_user_submission($employee->id, false);
        $DB->set_field('assign', 'blindmarking', 0, ['id' => $assignment->get_instance()->id]);
        $this->assertInstanceOf(\moodle_url::class, competency_review_queue_service::work_url($request));
        $link = $DB->get_record(course_module_competency::TABLE, ['cmid' => $assignment->get_course_module()->id,
            'competencyid' => $competencyid], '*', MUST_EXIST);
        $DB->set_field(course_module_competency::TABLE, 'ruleoutcome', course_module_competency::OUTCOME_EVIDENCE,
            ['id' => $link->id]);
        $this->assertNull(competency_review_queue_service::work_url($request));
        $DB->set_field(course_module_competency::TABLE, 'ruleoutcome', course_module_competency::OUTCOME_RECOMMEND,
            ['id' => $link->id]);
        $DB->set_field('assign_submission', 'timemodified', time() + 60, ['id' => $submission->id]);
        $this->assertNull(competency_review_queue_service::work_url($request));
    }

    public function test_reopened_attempt_and_repeated_completion_fall_back_even_in_same_second(): void {
        global $DB;
        [$competencyid, $roleid] = $this->fixture();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $employee = $generator->create_and_enrol($course, 'student');
        $reviewer = $generator->create_and_enrol($course, 'teacher');
        role_assign($roleid, $reviewer->id, \context_user::instance($employee->id)->id);
        api::add_competency_to_course($course->id, $competencyid);
        $assignment = $this->activity($competencyid, $course);
        $this->submit($assignment, $employee);
        $request = $this->request_id($employee, $competencyid);
        $this->setUser($reviewer);
        $this->assertInstanceOf(\moodle_url::class, competency_review_queue_service::work_url($request));

        // Reopening cannot be attributed from completion time, even in the same second.
        $submission = $assignment->get_user_submission($employee->id, false);
        $DB->set_field('assign_submission', 'attemptnumber', 1, ['id' => $submission->id]);
        $this->assertNull(competency_review_queue_service::work_url($request));
        $DB->set_field('assign_submission', 'attemptnumber', 0, ['id' => $submission->id]);
        $DB->set_field('assign_submission', 'latest', 0, ['id' => $submission->id]);
        $this->assertNull(competency_review_queue_service::work_url($request));
        $DB->set_field('assign_submission', 'latest', 1, ['id' => $submission->id]);

        // Two completion records of the same activity are not one unambiguous evidence.
        $evidence = $DB->get_record('competency_evidence', ['usercompetencyid' => $request], '*', MUST_EXIST);
        unset($evidence->id);
        $DB->insert_record('competency_evidence', $evidence);
        $this->assertNull(competency_review_queue_service::work_url($request));
        $this->assertInstanceOf(\moodle_url::class, competency_review_queue_service::queue()['rows'][0]['reviewurl']);
    }

    public function test_manual_rerequest_is_only_contextual_and_new_evidence_removes_shortcut(): void {
        global $DB;
        [$competencyid, $roleid] = $this->fixture();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $employee = $generator->create_and_enrol($course, 'student');
        $reviewer = $generator->create_and_enrol($course, 'teacher');
        role_assign($roleid, $reviewer->id, \context_user::instance($employee->id)->id);
        api::add_competency_to_course($course->id, $competencyid);
        $assignment = $this->activity($competencyid, $course);
        $this->submit($assignment, $employee);
        $request = $this->request_id($employee, $competencyid);
        $this->setUser($reviewer);
        $this->assertTrue(api::user_competency_start_review($employee->id, $competencyid));
        $this->assertTrue(api::user_competency_stop_review($employee->id, $competencyid));
        $this->setUser($employee);
        $this->assertTrue(api::user_competency_request_review($employee->id, $competencyid));
        $this->setUser($reviewer);
        // Moodle keeps the old evidence. Only a contextual (not exact-request) link is possible.
        $this->assertInstanceOf(\moodle_url::class, competency_review_queue_service::work_url($request));

        $evidence = $DB->get_record('competency_evidence', ['usercompetencyid' => $request], '*', MUST_EXIST);
        unset($evidence->id);
        $evidence->descidentifier = 'evidence_manualoverride';
        // Deliberately keep the same timestamp: the newer evidence ID breaks the tie.
        $DB->insert_record('competency_evidence', $evidence);
        $this->assertNull(competency_review_queue_service::work_url($request));
    }

    public function test_stale_link_is_rejected_after_enrolment_or_review_permission_revocation(): void {
        global $DB;
        [$competencyid, $roleid] = $this->fixture();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $employee = $generator->create_and_enrol($course, 'student');
        $reviewer = $generator->create_and_enrol($course, 'teacher');
        $usercontext = \context_user::instance($employee->id);
        role_assign($roleid, $reviewer->id, $usercontext->id);
        api::add_competency_to_course($course->id, $competencyid);
        $assignment = $this->activity($competencyid, $course);
        $this->submit($assignment, $employee);
        $request = $this->request_id($employee, $competencyid);
        $this->setUser($reviewer);
        $this->assertInstanceOf(\moodle_url::class, competency_review_queue_service::work_url($request));

        $DB->set_field('user_enrolments', 'status', ENROL_USER_SUSPENDED, ['userid' => $employee->id]);
        $this->assertNull(competency_review_queue_service::work_url($request));
        $DB->set_field('user_enrolments', 'status', ENROL_USER_ACTIVE, ['userid' => $employee->id]);
        $this->assertInstanceOf(\moodle_url::class, competency_review_queue_service::work_url($request));
        role_unassign($roleid, $reviewer->id, $usercontext->id);
        $this->assertNull(competency_review_queue_service::work_url($request));
        $this->assertSame(0, competency_review_queue_service::queue()['count']);
    }

    public function test_separate_group_prevents_link_to_other_group_submission(): void {
        [$competencyid, $roleid] = $this->fixture();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $employee = $generator->create_and_enrol($course, 'student');
        $reviewer = $generator->create_and_enrol($course, 'teacher');
        $employeegroup = $generator->create_group(['courseid' => $course->id]);
        $reviewergroup = $generator->create_group(['courseid' => $course->id]);
        $generator->create_group_member(['groupid' => $employeegroup->id, 'userid' => $employee->id]);
        $generator->create_group_member(['groupid' => $reviewergroup->id, 'userid' => $reviewer->id]);
        role_assign($roleid, $reviewer->id, \context_user::instance($employee->id)->id);
        api::add_competency_to_course($course->id, $competencyid);
        $assignment = $this->activity($competencyid, $course, ['groupmode' => SEPARATEGROUPS]);
        $modulecontext = $assignment->get_context();
        $restrictrole = $generator->create_role();
        assign_capability('moodle/site:accessallgroups', CAP_PROHIBIT, $restrictrole, $modulecontext->id);
        role_assign($restrictrole, $reviewer->id, $modulecontext->id);
        $this->submit($assignment, $employee);
        $request = $this->request_id($employee, $competencyid);

        $this->setUser($reviewer);
        $this->assertSame(1, competency_review_queue_service::queue()['count']);
        $this->assertNull(competency_review_queue_service::work_url($request));
    }
}
