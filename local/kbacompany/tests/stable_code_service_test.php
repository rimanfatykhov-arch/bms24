<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\stable_code_service;

/** Stable-code allocator regression tests. */
final class stable_code_service_test extends \advanced_testcase {
    public function test_sequences_are_company_and_scope_scoped(): void {
        $this->resetAfterTest();
        $companya = '11111111-1111-4111-8111-111111111111';
        $companyb = '22222222-2222-4222-8222-222222222222';
        $available = static function(string $candidate): bool { return true; };

        $this->assertSame('PLN-0001', stable_code_service::next($companya, 'adminscale:plan', 'PLN', $available));
        $this->assertSame('PLN-0002', stable_code_service::next($companya, 'adminscale:plan', 'PLN', $available));
        $this->assertSame('PRG-0001', stable_code_service::next($companya, 'adminscale:program', 'PRG', $available));
        $this->assertSame('PLN-0001', stable_code_service::next($companyb, 'adminscale:plan', 'PLN', $available));
    }

    public function test_allocator_skips_preexisting_code_and_never_moves_backwards(): void {
        global $DB;
        $this->resetAfterTest();
        $company = '33333333-3333-4333-8333-333333333333';
        $used = ['PLN-0001' => true];
        $available = static function(string $candidate) use (&$used): bool {
            return empty($used[$candidate]);
        };

        $code = stable_code_service::next($company, 'adminscale:plan', 'PLN', $available);
        $this->assertSame('PLN-0002', $code);
        $counter = $DB->get_record('local_kbacompany_codecounter',
            ['companyuuid' => $company, 'entityscope' => 'adminscale:plan'], '*', MUST_EXIST);
        $this->assertSame(2, (int)$counter->lastnumber);

        $used[$code] = true;
        $this->assertSame('PLN-0003', stable_code_service::next($company, 'adminscale:plan', 'PLN', $available));
    }
}
