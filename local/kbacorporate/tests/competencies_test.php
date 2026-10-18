<?php
namespace local_kbacorporate;

defined('MOODLE_INTERNAL') || die();

use local_kbacorporate\local\competencies;

/** Pure status tests: learning evidence must not become an authoritative level. */
final class competencies_test extends \advanced_testcase {
    public function test_configured_scale_supplies_zero_based_level_and_label(): void {
        global $DB;
        $this->resetAfterTest();
        $scaleid = (int)$DB->insert_record('scale', (object)[
            'courseid' => 0,
            'userid' => 0,
            'name' => 'BMS test scale',
            'scale' => 'Not oriented,Recognises,Explains,Applies independently',
            'description' => '',
            'descriptionformat' => FORMAT_PLAIN,
            'timemodified' => time(),
        ]);

        $method = new \ReflectionMethod(competencies::class, 'grade_to_level');
        $method->setAccessible(true);
        $this->assertSame(2, $method->invoke(null, 3, $scaleid));
        $this->assertSame('2 — Explains', competencies::level_label(2, $scaleid));
    }

    public function test_ungraded_course_result_requires_confirmation(): void {
        $row = (object)[
            'targetlevel' => 3,
            'currentlevel' => null,
            'provisionallevel' => 2,
            'activelearning' => true,
        ];
        $this->assertSame('needsconfirmation', $this->status_for($row));
    }

    public function test_unknown_current_is_not_level_zero(): void {
        $unknown = (object)[
            'targetlevel' => 2,
            'currentlevel' => null,
            'provisionallevel' => null,
            'activelearning' => false,
        ];
        $zero = (object)[
            'targetlevel' => 2,
            'currentlevel' => 0,
            'provisionallevel' => null,
            'activelearning' => false,
        ];
        $this->assertSame('notassessed', $this->status_for($unknown));
        $this->assertSame('notstarted', $this->status_for($zero));
    }

    public function test_target_level_reached_is_confirmed_without_course_autopromotion(): void {
        $row = (object)[
            'targetlevel' => 2,
            'currentlevel' => 2,
            'provisionallevel' => 3,
            'activelearning' => true,
        ];
        $this->assertSame('confirmed', $this->status_for($row));
    }

    private function status_for(\stdClass $row): string {
        $method = new \ReflectionMethod(competencies::class, 'status_for');
        $method->setAccessible(true);
        return $method->invoke(null, $row);
    }
}
