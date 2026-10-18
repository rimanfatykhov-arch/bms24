<?php
namespace local_kbacorporate;

defined('MOODLE_INTERNAL') || die();

use local_kbacorporate\local\role_course_coverage;

/** Regression guards for company-scoped role course coverage. */
final class role_course_coverage_test extends \advanced_testcase {
    public function test_company_courses_excludes_hidden_courses(): void {
        global $DB;
        $this->resetAfterTest();
        $visible = $this->getDataGenerator()->create_course(['visible' => 1]);
        $hidden = $this->getDataGenerator()->create_course(['visible' => 0]);
        $companyid = (int)$DB->insert_record('local_kbacorp_company', (object)[
            'name' => 'Coverage Company', 'active' => 1, 'timecreated' => time(), 'timemodified' => time(),
        ]);
        $DB->insert_record('local_kbacorp_course', (object)[
            'companyid' => $companyid, 'courseid' => (int)$visible->id, 'groupid' => 0, 'active' => 1,
            'timecreated' => time(), 'timemodified' => time(),
        ]);
        $DB->insert_record('local_kbacorp_course', (object)[
            'companyid' => $companyid, 'courseid' => (int)$hidden->id, 'groupid' => 0, 'active' => 1,
            'timecreated' => time(), 'timemodified' => time(),
        ]);
        $courses = role_course_coverage::company_courses($companyid);
        $this->assertArrayHasKey((int)$visible->id, $courses);
        $this->assertArrayNotHasKey((int)$hidden->id, $courses);
    }

    public function test_company_courses_requires_company_context(): void {
        $this->resetAfterTest();
        $this->assertSame([], role_course_coverage::company_courses(0));
    }


    public function test_company_courses_without_competencies_reports_unmapped_active_course(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['visible' => 1]);
        $companyid = (int)$DB->insert_record('local_kbacorp_company', (object)[
            'name' => 'Integrity Company', 'active' => 1, 'timecreated' => time(), 'timemodified' => time(),
        ]);
        $DB->insert_record('local_kbacorp_course', (object)[
            'companyid' => $companyid, 'courseid' => (int)$course->id, 'groupid' => 0, 'active' => 1,
            'timecreated' => time(), 'timemodified' => time(),
        ]);

        $courses = role_course_coverage::company_courses_without_competencies($companyid);
        $this->assertArrayHasKey((int)$course->id, $courses);
    }
}
