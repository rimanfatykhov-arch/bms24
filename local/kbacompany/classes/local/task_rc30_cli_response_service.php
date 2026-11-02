<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * RC30 CLI output contract. Never expose SQL, paths, UUIDs or exception messages
 * in an automated preflight report. Errors are still parseable as JSON.
 * Build 2026101866.
 */
final class task_rc30_cli_response_service {
    /** Only fixed, non-sensitive diagnostic categories may enter the report. */
    private const ERROR_CODES = [
        'invalid_arguments', 'unsupported_format', 'invalid_company_uuid',
        'missing_rc30_table', 'database_query_error', 'preflight_execution_error',
    ];

    /** @return array<string,mixed> */
    public static function failure(string $tool, string $code, string $scope,
            bool $strict): array {
        if (!in_array($tool, ['acceptance', 'integrity'], true)) {
            throw new \coding_exception('Invalid RC30 preflight tool');
        }
        if (!in_array($code, self::ERROR_CODES, true)) {
            $code = 'preflight_execution_error';
        }
        if (!in_array($scope, ['company', 'all', 'unspecified'], true)) {
            $scope = 'unspecified';
        }
        $payload = [
            'schema_version'=>1,
            'scope'=>$scope,
            'strict'=>$strict,
            'read_only'=>true,
            'diagnostic_complete'=>false,
            'rc30_promotion_approved'=>false,
            'errors'=>[$code],
            'warnings'=>[],
        ];
        if ($tool === 'acceptance') {
            $payload['technical_gate'] = 'failed';
        } else {
            $payload['gate'] = 'failed';
        }
        return $payload;
    }

    public static function error_code(\Throwable $exception): string {
        if ($exception instanceof \invalid_parameter_exception) {
            return 'invalid_company_uuid';
        }
        // The integrity service reports missing required schemas with a fixed
        // prefix. Never print the table name or the exception itself.
        if ($exception instanceof \coding_exception &&
                strpos($exception->getMessage(), 'RC30 schema missing:') === 0) {
            return 'missing_rc30_table';
        }
        if ($exception instanceof \dml_exception) {
            return 'database_query_error';
        }
        return 'preflight_execution_error';
    }

    /**
     * Emit a machine-readable failure to stdout or a concise code to stderr.
     * Caller retains ownership of exit(2); this method never mutates data.
     */
    public static function emit_failure(string $tool, string $format, string $code,
            string $scope, bool $strict): void {
        $result = self::failure($tool, $code, $scope, $strict);
        if ($format === 'json') {
            echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        } else {
            fwrite(STDERR, 'RC30 ' . $tool . ': ' . $result['errors'][0] . "\n");
        }
    }
}
