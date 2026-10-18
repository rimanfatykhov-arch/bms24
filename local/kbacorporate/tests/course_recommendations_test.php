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
        $DB->insert_record('competency_coursecomp', (object)[
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

    public function test_recommendations_require_company_and_competency_context(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->assertSame([], course_recommendations::for_competency((int)$user->id, 0, 1));
        $this->assertSame([], course_recommendations::for_competency((int)$user->id, 1, 0));
    }
}
