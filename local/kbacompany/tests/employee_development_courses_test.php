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
        $this->assertSame([$competency => [$eligibleid => ['id' => $eligibleid, 'name' => 'eligible', 'percentage' => null]]],
            $context['development_courses']);
        $this->assertSame((int)$rating->get('id'), $context['moodle_ratings'][$competency]['id']);
        $this->assertSame('A', $context['moodle_ratings'][$competency]['label']);

        $this->setAdminUser();
        $adminid = (int)get_admin()->id;
        $this->assertTrue($generator->enrol_user($adminid, $eligibleid, 'student'));
        $context = employee_workspace_service::tab_context($company, $employee->id, $adminid, 'competencies', []);
        $this->assertSame([], $context['development_courses']);
    }
}
