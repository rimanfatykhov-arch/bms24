<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\business_date_service;
use local_kbacompany\local\task_service;

/** User-timezone-dependent ISO values; requires Moodle PHPUnit test database. */
class business_date_service1876_test extends \advanced_testcase {
    public function test_user_timezone_and_week_boundary(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user(['timezone' => 'Europe/Helsinki']);
        $this->setUser($user);
        $stamp = strtotime('2026-11-01T23:30:00+00:00');
        $this->assertSame('2026-11-02', business_date_service::day($stamp));
        $this->assertSame(['today'=>'2026-11-02', 'until'=>'2026-11-08'],
            task_service::reporting_dates($stamp));
    }

    public function test_dst_yesterday_is_calendar_arithmetic(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user(['timezone' => 'Europe/Helsinki']);
        $this->setUser($user);
        $stamp = strtotime('2026-03-29T21:30:00+00:00');
        $this->assertSame('2026-03-30', business_date_service::day($stamp));
        $this->assertSame('2026-03-29', business_date_service::yesterday($stamp));
    }

    public function test_html_datetime_local_is_not_localized(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user(['timezone' => 'Europe/Helsinki']);
        $this->setUser($user);
        $stamp = strtotime('2026-11-01T23:30:00+00:00');
        $this->assertSame('2026-11-02T01:30', business_date_service::datetime_local($stamp));
        $this->assertSame('2026-11-02T01:00', business_date_service::datetime_local($stamp, true));
    }
}
