<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\task_rc30_cli_response_service as cliresponse;

/** Read-only diagnostic output must be safe and machine-readable even on failures. */
final class task_rc30_cli_response1866_test extends \advanced_testcase {
    public function test_json_failure_contract(): void {
        $acceptance = cliresponse::failure('acceptance', 'database_query_error', 'company', true);
        $this->assertEquals('failed', $acceptance['technical_gate']);
        $this->assertFalse($acceptance['diagnostic_complete']);
        $this->assertFalse($acceptance['rc30_promotion_approved']);
        $this->assertTrue($acceptance['read_only']);
        $this->assertSame(['database_query_error'], $acceptance['errors']);
        $this->assertSame('company', $acceptance['scope']);
        $this->assertTrue($acceptance['strict']);

        $integrity = cliresponse::failure('integrity', 'missing_rc30_table', 'all', false);
        $this->assertSame('failed', $integrity['gate']);
        $this->assertSame(['missing_rc30_table'], $integrity['errors']);
        $this->assertSame('all', $integrity['scope']);
        $this->assertFalse($integrity['strict']);
    }

    public function test_error_classification_does_not_leak_exception_messages(): void {
        $this->assertSame('invalid_company_uuid',
            cliresponse::error_code(new \invalid_parameter_exception('PRIVATE UUID')));
        $this->assertSame('database_query_error',
            cliresponse::error_code(new \dml_exception('PRIVATE SQL')));
        $this->assertSame('missing_rc30_table',
            cliresponse::error_code(new \coding_exception('RC30 schema missing: private_table')));
        $this->assertSame('preflight_execution_error',
            cliresponse::error_code(new \RuntimeException('PRIVATE PATH')));
        $this->assertSame(['preflight_execution_error'],
            cliresponse::failure('acceptance', 'PRIVATE SQL', '/internal/uuid', false)['errors']);
    }
}
