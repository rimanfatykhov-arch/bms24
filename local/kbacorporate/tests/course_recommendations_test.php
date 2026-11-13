<?php
namespace local_kbacorporate;

defined('MOODLE_INTERNAL') || die();

use local_kbacorporate\local\course_recommendations;
use local_kbacorporate\local\repository;

/** Guard tests for the company boundary of recommendations. */
final class course_recommendations_test extends \advanced_testcase {
    public function test_hidden_course_is_not_disclosed_even_when_enrolled(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course(['visible' => 0]);
        $this->getDataGenerator()->enrol_user((int)$user->id, (int)$course->id);
        $companyid = (int)$DB->insert_record('local_kbacorp_company', (object)[
            'name' => 'Recommendation Company',
            'active' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        repository::add_course($companyid, (int)$course->id, 0);

        $generator = $this->getDataGenerator()->get_plugin_generator('core_competency');
        $framework = $generator->create_framework();
        $competency = $generator->create_competency([
            'competencyframeworkid' => $framework->get('id'),
        ]);
        $generator->create_course_competency([
            'courseid' => (int)$course->id,
            'competencyid' => (int)$competency->get('id'),
            'sortorder' => 0,
            'ruleoutcome' => 0,
        ]);

        $this->assertSame([], course_recommendations::for_competency(
            (int)$user->id,
            $companyid,
            (int)$competency->get('id')
        ));
    }

    /** Company with one course linked to one competency. */
    private function scenario(array $courseoptions = []): array {
        global $DB;
        $course = $this->getDataGenerator()->create_course($courseoptions + ['visible' => 1]);
        $companyid = (int)$DB->insert_record('local_kbacorp_company', (object)[
            'name' => 'Recommendation Company ' . random_string(6),
            'active' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        repository::add_course($companyid, (int)$course->id, 0);
        $generator = $this->getDataGenerator()->get_plugin_generator('core_competency');
        $framework = $generator->create_framework();
        $competency = $generator->create_competency(['competencyframeworkid' => $framework->get('id')]);
        $generator->create_course_competency([
            'courseid' => (int)$course->id,
            'competencyid' => (int)$competency->get('id'),
            'sortorder' => 0,
            'ruleoutcome' => 0,
        ]);
        return [$course, $companyid, (int)$competency->get('id')];
    }

    private function disable_non_manual_enrolment(int $courseid): void {
        foreach (enrol_get_instances($courseid, false) as $instance) {
            if ($instance->enrol !== 'manual') {
                enrol_get_plugin($instance->enrol)->update_status($instance, ENROL_INSTANCE_DISABLED);
            }
        }
    }

    public function test_enrolled_student_without_course_view_receives_recommendation(): void {
        $this->resetAfterTest();
        [$course, $companyid, $competencyid] = $this->scenario();
        $this->disable_non_manual_enrolment((int)$course->id);
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user((int)$user->id, (int)$course->id, 'student');
        $this->assertFalse(has_capability('moodle/course:view', \context_course::instance((int)$course->id), $user));

        $result = course_recommendations::for_competency((int)$user->id, $companyid, $competencyid);
        $this->assertCount(1, $result);
        $this->assertSame((int)$course->id, $result[0]->courseid);
        $this->assertSame('enrolled', $result[0]->enrolmentstatus);
        $this->assertNotSame('', $result[0]->url);
    }

    public function test_suspended_enrolment_is_not_recommended_as_enrolled(): void {
        $this->resetAfterTest();
        [$course, $companyid, $competencyid] = $this->scenario();
        $this->disable_non_manual_enrolment((int)$course->id);
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user((int)$user->id, (int)$course->id, 'student', 'manual', 0, 0,
            ENROL_USER_SUSPENDED);
        $this->assertSame([], course_recommendations::for_competency((int)$user->id, $companyid, $competencyid));
    }

    public function test_unenrolled_user_without_licence_or_self_enrolment_gets_nothing(): void {
        $this->resetAfterTest();
        [$course, $companyid, $competencyid] = $this->scenario();
        $this->disable_non_manual_enrolment((int)$course->id);
        $user = $this->getDataGenerator()->create_user();
        $this->assertSame([], course_recommendations::for_competency((int)$user->id, $companyid, $competencyid));
    }

    public function test_open_self_enrolment_is_available_with_link(): void {
        $this->resetAfterTest();
        [$course, $companyid, $competencyid] = $this->scenario();
        $this->disable_non_manual_enrolment((int)$course->id);
        enrol_get_plugin('self')->add_instance($course, ['status' => ENROL_INSTANCE_ENABLED]);
        $user = $this->getDataGenerator()->create_user();

        $result = course_recommendations::for_competency((int)$user->id, $companyid, $competencyid);
        $this->assertCount(1, $result);
        $this->assertSame('available', $result[0]->enrolmentstatus);
        $this->assertNotSame('', $result[0]->url);
    }

    public function test_course_of_another_company_is_not_disclosed(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $companyb, $competencyid] = $this->scenario();
        $companya = (int)$DB->insert_record('local_kbacorp_company', (object)[
            'name' => 'Foreign Company', 'active' => 1, 'timecreated' => time(), 'timemodified' => time(),
        ]);
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user((int)$user->id, (int)$course->id, 'student');
        $this->assertSame([], course_recommendations::for_competency((int)$user->id, $companya, $competencyid));
        $this->assertCount(1, course_recommendations::for_competency((int)$user->id, $companyb, $competencyid));
    }

    public function test_revoked_company_member_gets_no_recommendations(): void {
        global $DB;
        $this->resetAfterTest();
        if (!class_exists('\local_kbacompany\local\membership_access_service') ||
                !$DB->get_manager()->table_exists('local_kbacompany_revoke')) {
            $this->markTestSkipped('BMS revocation policy is not installed.');
        }
        [$course, $companyid, $competencyid] = $this->scenario();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user((int)$user->id, (int)$course->id, 'student');
        $this->assertCount(1, course_recommendations::for_competency((int)$user->id, $companyid, $competencyid));

        $uuid = \core\uuid::generate();
        $DB->insert_record('local_kbacompany_idreg', (object)['sourcecomponent' => 'local_kbacorporate',
            'sourceentity' => 'company', 'sourceid' => $companyid, 'uuid' => $uuid,
            'timecreated' => time(), 'timemodified' => time()]);
        $DB->insert_record('local_kbacompany_revoke', (object)['companyuuid' => $uuid, 'userid' => (int)$user->id,
            'revokedby' => 2, 'timecreated' => time(), 'reopenedby' => 0, 'timereopened' => 0]);
        $this->assertSame([], course_recommendations::for_competency((int)$user->id, $companyid, $competencyid));
    }

    public function test_admin_sees_hidden_course_but_ordinary_employee_does_not(): void {
        $this->resetAfterTest();
        [$course, $companyid, $competencyid] = $this->scenario(['visible' => 0]);
        $admin = get_admin();
        $this->getDataGenerator()->enrol_user((int)$admin->id, (int)$course->id, 'student');
        $employee = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user((int)$employee->id, (int)$course->id, 'student');

        $adminresult = course_recommendations::for_competency((int)$admin->id, $companyid, $competencyid);
        $this->assertCount(1, $adminresult);
        $this->assertSame('enrolled', $adminresult[0]->enrolmentstatus);
        $this->assertSame([], course_recommendations::for_competency((int)$employee->id, $companyid, $competencyid));
    }

    public function test_recommendations_require_company_and_competency_context(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->assertSame([], course_recommendations::for_competency((int)$user->id, 0, 1));
        $this->assertSame([], course_recommendations::for_competency((int)$user->id, 1, 0));
    }
}
