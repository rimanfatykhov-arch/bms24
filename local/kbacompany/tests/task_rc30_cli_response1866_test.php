<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\task_rc30_cli_response_service as cliresponse;

/** Read-only diagnostic output must be safe and machine-readable even on failures. */
final class task_rc30_cli_response1866_test extends \advanced_testcase {
    public function test_missing_schema_uses_real_moodle_hint_not_localised_message(): void {
        $exception = new \coding_exception('RC30 schema missing: PRIVATE_TABLE', 'PRIVATE SQL /internal/path');
        $this->assertSame('RC30 schema missing: PRIVATE_TABLE', $exception->a);
        // Moodle adds its translated codingerror text before the hint.
        $this->assertNotSame(0, strpos($exception->getMessage(), 'RC30 schema missing:'));
        $this->assertSame('missing_rc30_table', cliresponse::error_code($exception));

        $this->assertSame('preflight_execution_error', cliresponse::error_code(
            new \RuntimeException('RC30 schema missing: PRIVATE_TABLE')));
        $this->assertSame('preflight_execution_error', cliresponse::error_code(
            new \coding_exception('Unrelated hint mentioning RC30 schema missing: PRIVATE_TABLE')));
        $this->assertSame('preflight_execution_error', cliresponse::error_code(
            new \coding_exception('Unrelated hint', 'RC30 schema missing: PRIVATE_TABLE')));
        // A message-only spoof must not override the typed Moodle hint.
        $messageonly = new class('Unrelated hint') extends \coding_exception {
            public function __construct($hint) {
                parent::__construct($hint);
                $this->message = 'RC30 schema missing: PRIVATE_TABLE';
            }
        };
        $this->assertSame('preflight_execution_error', cliresponse::error_code($messageonly));
        $exception->a = (object)['hint' => 'RC30 schema missing: PRIVATE_TABLE'];
        $this->assertSame('preflight_execution_error', cliresponse::error_code($exception));
        $exception->a = null;
        $this->assertSame('preflight_execution_error', cliresponse::error_code($exception));
    }

    public function test_classified_missing_schema_json_contains_no_private_diagnostics(): void {
        $exception = new \coding_exception('RC30 schema missing: PRIVATE_TABLE',
            'PRIVATE SQL /internal/path 22222222-2222-4222-8222-222222222222');
        $code = cliresponse::error_code($exception);
        foreach (['acceptance', 'integrity'] as $tool) {
            $payload = cliresponse::failure($tool, $code, 'PRIVATE_SCOPE', true);
            ob_start();
            try {
                cliresponse::emit_failure($tool, 'json', $code, 'PRIVATE_SCOPE', true);
                $json = ob_get_contents();
            } finally {
                ob_end_clean();
            }
            $this->assertSame($payload, json_decode($json, true, 512, JSON_THROW_ON_ERROR));
            $this->assertSame(['missing_rc30_table'], $payload['errors']);
            $this->assertSame('unspecified', $payload['scope']);
            $this->assertSame('failed', $payload[$tool === 'acceptance' ? 'technical_gate' : 'gate']);
            $this->assertFalse($payload['diagnostic_complete']);
            $this->assertFalse($payload['rc30_promotion_approved']);
            foreach (['PRIVATE', '/internal/path', '22222222-2222-4222-8222-222222222222',
                    'RC30 schema missing:', 'codingerror'] as $private) {
                $this->assertStringNotContainsString($private, $json);
            }
        }
        $this->assertDebuggingNotCalled();
    }

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
