<?php
namespace local_kbacompany;

use local_kbacompany\local\competency_catalog_service;
use local_kbacompany\local\employee_workspace_service;

final class employee_development_courses_test extends \advanced_testcase {
    private function fixture(): array {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('enabled', 1, 'core_competency');
        $generator = $this->getDataGenerator();
        $employee = $generator->create_user();
        $peer = $generator->create_user();
        $outsider = $generator->create_user();
        $company = '11111111-1111-4111-8111-111111111111';
        $position = '22222222-2222-4222-8222-222222222222';
        $profile = '33333333-3333-4333-8333-333333333333';
        $DB->insert_record('local_kbacompany_company', ['uuid' => $company, 'name' => 'QA Workshop',
            'sourcekind' => 'bms', 'bmsclient' => 1]);
        foreach ([$employee, $peer] as $index => $user) {
            $DB->insert_record('local_kbacompany_member', [
                'uuid' => '44444444-4444-4444-8444-44444444444' . $index,
                'companyuuid' => $company, 'userid' => $user->id,
            ]);
        }
        $DB->insert_record('local_kbacompany_position', ['companyuuid' => $company, 'uuid' => $position,
            'branchuuid' => '', 'orgnodeuuid' => '', 'code' => 'QA-01', 'name' => 'Production lead']);
        $DB->insert_record('local_kbacompany_posassign', ['companyuuid' => $company,
            'positionuuid' => $position, 'userid' => $employee->id]);
        $DB->insert_record('local_kbacompany_roledna', ['companyuuid' => $company, 'uuid' => $profile,
            'positionuuid' => $position, 'title' => 'Production lead', 'status' => 'published']);
        $competencies = $generator->get_plugin_generator('core_competency');
        $framework = $competencies->create_framework();
        $competency = $competencies->create_competency(['competencyframeworkid' => $framework->get('id')]);
        $id = (int)$competency->get('id');
        competency_catalog_service::bind_framework($company, (int)get_admin()->id, (int)$framework->get('id'));
        $DB->insert_record('local_kbacompany_rolednacomp', ['companyuuid' => $company,
            'rolednauuid' => $profile, 'competencyid' => $id, 'targetlevel' => 2]);
        return [$company, $employee, $peer, $outsider, $id];
    }

    public function test_self_only_and_company_scope(): void {
        [$company, $employee, $peer, $outsider, $competency] = $this->fixture();
        $this->setUser($employee);
        $context = employee_workspace_service::tab_context($company, $employee->id, $employee->id, 'competencies', []);
        $this->assertSame([$competency], array_column($context['competencies']['requirements'], 'competency_id'));
        $this->assertSame([], $context['development_courses']);

        $this->setAdminUser();
        $context = employee_workspace_service::tab_context($company, $employee->id, get_admin()->id, 'competencies', []);
        $this->assertTrue($context['can_inspect']);
        $this->assertSame([], $context['development_courses']);

        $this->setUser($peer);
        $context = employee_workspace_service::tab_context($company, $employee->id, $peer->id, 'competencies', []);
        $this->assertTrue($context['restricted']);
        $this->assertArrayNotHasKey('development_courses', $context);

        $this->setUser($outsider);
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('companynotavailable', 'local_kbacompany'));
        employee_workspace_service::tab_context($company, $employee->id, $outsider->id, 'competencies', []);
    }

    public function test_two_company_requirements_do_not_leak_learning_results(): void {
        global $DB;
        [$company, $employee, , $outsider, $competency] = $this->fixture();
        $othercompany = '55555555-5555-4555-8555-555555555555';
        $position = '66666666-6666-4666-8666-666666666666';
        $profile = '77777777-7777-4777-8777-777777777777';
        $DB->insert_record('local_kbacompany_company', ['uuid' => $othercompany, 'name' => 'Other Workshop',
            'sourcekind' => 'bms', 'bmsclient' => 1]);
        foreach ([$employee, $outsider] as $index => $user) {
            $DB->insert_record('local_kbacompany_member', [
                'uuid' => '88888888-8888-4888-8888-88888888888' . $index,
                'companyuuid' => $othercompany, 'userid' => $user->id]);
        }
        $DB->insert_record('local_kbacompany_position', ['companyuuid' => $othercompany, 'uuid' => $position,
            'branchuuid' => '', 'orgnodeuuid' => '', 'code' => 'OTHER-01', 'name' => 'Other lead']);
        $DB->insert_record('local_kbacompany_posassign', ['companyuuid' => $othercompany,
            'positionuuid' => $position, 'userid' => $employee->id]);
        $DB->insert_record('local_kbacompany_roledna', ['companyuuid' => $othercompany, 'uuid' => $profile,
            'positionuuid' => $position, 'title' => 'Other lead', 'status' => 'published']);
        $generator = $this->getDataGenerator();
        $cg = $generator->get_plugin_generator('core_competency');
        $framework = $cg->create_framework();
        $othercompetency = $cg->create_competency(['competencyframeworkid' => $framework->get('id')]);
        $otherid = (int)$othercompetency->get('id');
        competency_catalog_service::bind_framework($othercompany, (int)get_admin()->id, (int)$framework->get('id'));
        $DB->insert_record('local_kbacompany_rolednacomp', ['companyuuid' => $othercompany,
            'rolednauuid' => $profile, 'competencyid' => $otherid, 'targetlevel' => 2]);
        $courses = [];
        foreach ([$competency, $otherid] as $id) {
            $course = $generator->create_course();
            $generator->enrol_user($employee->id, $course->id, 'student');
            $cg->create_course_competency(['courseid' => $course->id, 'competencyid' => $id]);
            $cg->create_user_competency(['userid' => $employee->id, 'competencyid' => $id,
                'grade' => 1, 'proficiency' => false]);
            $courses[$id] = (int)$course->id;
        }
        $this->setUser($employee);
        foreach ([$company => $competency, $othercompany => $otherid] as $scope => $id) {
            $context = employee_workspace_service::tab_context($scope, $employee->id, $employee->id, 'competencies', []);
            $this->assertSame([$id], array_column($context['competencies']['requirements'], 'competency_id'));
            $this->assertSame([$id], array_keys($context['development_courses']));
            $this->assertSame([$courses[$id]], array_keys($context['development_courses'][$id]));
            $this->assertSame([$id], array_keys($context['moodle_ratings']));
        }
        $this->setUser($outsider);
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('companynotavailable', 'local_kbacompany'));
        employee_workspace_service::tab_context($company, $employee->id, $outsider->id, 'competencies', []);
    }

    public function test_course_access_enrolment_and_untracked_progress(): void {
        [$company, $employee, , , $competency] = $this->fixture();
        $generator = $this->getDataGenerator();
        $competencies = $generator->get_plugin_generator('core_competency');
        $rating = $competencies->create_user_competency(['userid' => $employee->id, 'competencyid' => $competency,
            'grade' => 1, 'proficiency' => false, 'status' => \core_competency\user_competency::STATUS_IDLE]);
        $options = [
            'eligible' => ['enablecompletion' => 0],
            'hidden' => ['visible' => 0],
            'suspended' => [],
            'not enrolled' => [],
            'unrelated' => [],
        ];
        foreach ($options as $label => $settings) {
            $course = $generator->create_course($settings + ['fullname' => $label]);
            if ($label !== 'unrelated') {
                $competencies->create_course_competency(['courseid' => $course->id, 'competencyid' => $competency]);
            }
            if ($label !== 'not enrolled') {
                $status = $label === 'suspended' ? ENROL_USER_SUSPENDED : ENROL_USER_ACTIVE;
                $this->assertTrue($generator->enrol_user($employee->id, $course->id, 'student', 'manual', 0, 0, $status));
            }
            if ($label === 'eligible') {
                $eligibleid = (int)$course->id;
            }
        }
        $this->setUser($employee);
        $context = employee_workspace_service::tab_context($company, $employee->id, $employee->id, 'competencies', []);
        $this->assertSame([$competency => [$eligibleid => ['id' => $eligibleid, 'name' => 'eligible',
            'percentage' => null, 'assignments' => []]]],
            $context['development_courses']);
        $this->assertSame((int)$rating->get('id'), $context['moodle_ratings'][$competency]['id']);
        $this->assertSame('A', $context['moodle_ratings'][$competency]['label']);

        $this->setAdminUser();
        $adminid = (int)get_admin()->id;
        $this->assertTrue($generator->enrol_user($adminid, $eligibleid, 'student'));
        $context = employee_workspace_service::tab_context($company, $employee->id, $adminid, 'competencies', []);
        $this->assertSame([], $context['development_courses']);
    }

    public function test_assignment_results_visibility_zero_and_scale(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');
        [$company, $employee, , , $competency] = $this->fixture();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['showgrades' => 1]);
        $generator->enrol_user($employee->id, $course->id, 'student');
        $cg = $generator->get_plugin_generator('core_competency');
        $cg->create_course_competency(['courseid' => $course->id, 'competencyid' => $competency]);
        $scale = $generator->create_scale(['scale' => 'Developing,Ready']);
        $modules = [];
        foreach (['zero', 'hiddenitem', 'hiddengrade', 'future', 'workflow', 'blind', 'scale', 'unrelated', 'hiddenmodule'] as $kind) {
            $a = $generator->create_module('assign', ['course' => $course->id,
                'grade' => $kind === 'scale' ? -$scale->id : 100,
                'markingworkflow' => $kind === 'workflow' ? 1 : 0,
                'blindmarking' => $kind === 'blind' ? 1 : 0,
                'visible' => $kind === 'hiddenmodule' ? 0 : 1]);
            if ($kind !== 'unrelated') {
                $cg->create_course_module_competency(['cmid' => $a->cmid, 'competencyid' => $competency]);
            }
            grade_update('test', $course->id, 'mod', 'assign', $a->id, 0,
                ['userid' => $employee->id, 'rawgrade' => $kind === 'scale' ? 2 : 0]);
            $item = \grade_item::fetch(['courseid' => $course->id, 'itemmodule' => 'assign', 'iteminstance' => $a->id]);
            if ($kind === 'hiddenitem' || $kind === 'future') {
                $item->set_hidden($kind === 'future' ? time() + 3600 : 1);
            }
            if ($kind === 'hiddengrade') {
                $grade = \grade_grade::fetch(['itemid' => $item->id, 'userid' => $employee->id]);
                $grade->set_hidden(1);
            }
            $modules[$kind] = $a;
        }
        grade_regrade_final_grades($course->id);
        $this->setUser($employee);
        $context = employee_workspace_service::tab_context($company, $employee->id, $employee->id, 'competencies', []);
        $results = $context['development_courses'][$competency][$course->id]['assignments'];
        $this->assertArrayNotHasKey($modules['unrelated']->cmid, $results);
        $this->assertArrayNotHasKey($modules['hiddenmodule']->cmid, $results);
        $this->assertSame('graded', $results[$modules['zero']->cmid]['state']);
        $this->assertNotEmpty($results[$modules['zero']->cmid]['grade']);
        $this->assertSame('Ready', $results[$modules['scale']->cmid]['grade']);
        foreach (['hiddenitem', 'hiddengrade', 'future', 'workflow', 'blind'] as $kind) {
            $this->assertNull($results[$modules[$kind]->cmid]['grade'], $kind);
            $this->assertSame('unavailable', $results[$modules[$kind]->cmid]['state'], $kind);
        }
        $DB->insert_record('assign_user_flags', ['assignment' => $modules['workflow']->id,
            'userid' => $employee->id, 'workflowstate' => ASSIGN_MARKING_WORKFLOW_STATE_RELEASED]);
        $item = \grade_item::fetch(['courseid' => $course->id, 'itemmodule' => 'assign',
            'iteminstance' => $modules['zero']->id]);
        $DB->set_field('grade_items', 'needsupdate', 1, ['id' => $item->id]);
        $before = $DB->get_record('grade_grades', ['itemid' => $item->id, 'userid' => $employee->id]);
        $context = employee_workspace_service::tab_context($company, $employee->id, $employee->id, 'competencies', []);
        $results = $context['development_courses'][$competency][$course->id]['assignments'];
        $this->assertNull($results[$modules['zero']->cmid]['grade']);
        $this->assertSame(1, (int)$DB->get_field('grade_items', 'needsupdate', ['id' => $item->id]));
        $this->assertEquals($before, $DB->get_record('grade_grades', ['itemid' => $item->id, 'userid' => $employee->id]));
        $this->assertSame('graded', $results[$modules['workflow']->cmid]['state']);
        $role = $DB->get_record('role', ['shortname' => 'student'], '*', MUST_EXIST);
        assign_capability('moodle/grade:view', CAP_PROHIBIT, $role->id, \context_course::instance($course->id)->id);
        accesslib_clear_all_caches_for_unit_testing();
        $context = employee_workspace_service::tab_context($company, $employee->id, $employee->id, 'competencies', []);
        foreach ($context['development_courses'][$competency][$course->id]['assignments'] as $result) {
            $this->assertNull($result['grade']);
        }
        unassign_capability('moodle/grade:view', $role->id, \context_course::instance($course->id)->id);
        accesslib_clear_all_caches_for_unit_testing();
        $DB->set_field('course', 'showgrades', 0, ['id' => $course->id]);
        rebuild_course_cache($course->id, true);
        $context = employee_workspace_service::tab_context($company, $employee->id, $employee->id, 'competencies', []);
        foreach ($context['development_courses'][$competency][$course->id]['assignments'] as $result) {
            $this->assertNull($result['grade']);
        }
    }

    public function test_confirmation_states_are_not_assignment_grades(): void {
        [$company, $employee, , , $competency] = $this->fixture();
        $this->setUser($employee);
        $context = employee_workspace_service::tab_context($company, $employee->id, $employee->id, 'competencies', []);
        $this->assertSame('unassessed', $context['moodle_ratings'][$competency]['state']);
        $this->setAdminUser();
        $record = $this->getDataGenerator()->get_plugin_generator('core_competency')->create_user_competency([
            'userid' => $employee->id, 'competencyid' => $competency, 'grade' => 1, 'proficiency' => false]);
        foreach ([['notproficient', false, 0], ['proficient', true, 0],
                ['review', true, \core_competency\user_competency::STATUS_WAITING_FOR_REVIEW]] as [$state, $proficiency, $status]) {
            $record->set('proficiency', $proficiency)->set('status', $status)->update();
            $this->setUser($employee);
            $context = employee_workspace_service::tab_context($company, $employee->id, $employee->id, 'competencies', []);
            $this->assertSame($state, $context['moodle_ratings'][$competency]['state']);
            if ($state === 'review') {
                $this->assertSame('', $context['moodle_ratings'][$competency]['label']);
            }
            $this->setAdminUser();
        }
    }
}
