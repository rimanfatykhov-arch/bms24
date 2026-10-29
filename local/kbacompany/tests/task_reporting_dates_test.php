<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\task_service;

/** build2026101839: task deadline semantics use local calendar days. */
final class task_reporting_dates_test extends \advanced_testcase {
    /** A UTC date may differ from the person's local calendar date. */
    public function test_today_and_seven_day_window_use_moodle_user_timezone(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user(['timezone' => 'Pacific/Kiritimati']);
        $this->setUser($user);
        $now = strtotime('2026-10-27 11:00:00 UTC');
        $this->assertSame(['today' => '2026-10-28', 'until' => '2026-11-03'],
            task_service::reporting_dates($now));
    }

    /** Adding six calendar days must behave correctly across DST boundaries. */
    public function test_seven_day_window_is_not_timestamp_arithmetic_across_dst(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user(['timezone' => 'America/New_York']);
        $this->setUser($user);
        $now = strtotime('2026-03-08 04:30:00 UTC');
        $this->assertSame(['today' => '2026-03-07', 'until' => '2026-03-13'],
            task_service::reporting_dates($now));
    }

    public function test_today_includes_today_and_six_additional_dates(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user(['timezone' => 'Europe/Stockholm']);
        $this->setUser($user);
        $now = strtotime('2026-10-25 23:30:00 UTC');
        $this->assertSame(['today' => '2026-10-26', 'until' => '2026-11-01'],
            task_service::reporting_dates($now));
    }
}
