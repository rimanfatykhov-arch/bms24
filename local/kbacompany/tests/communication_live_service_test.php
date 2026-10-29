<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\communication_live_service;

/** Fingerprint is stable, opaque, and reacts to changes without exposing user content. */
final class communication_live_service_test extends \advanced_testcase {
    public function test_fingerprint_is_stable_and_opaque(): void {
        $this->resetAfterTest();
        $a = communication_live_service::fingerprint(['t:some-thread', 'read:3']);
        $b = communication_live_service::fingerprint(['t:some-thread', 'read:3']);
        $c = communication_live_service::fingerprint(['t:some-thread', 'read:4']);
        $this->assertSame($a, $b);
        $this->assertNotSame($a, $c);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $a);
        $this->assertStringNotContainsString('some-thread', $a);
    }
}
