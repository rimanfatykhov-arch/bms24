<?php
namespace local_bitrixsync\exception;

defined('MOODLE_INTERNAL') || die();

class bitrix_api_exception extends \Exception {
    /** @var string */
    private $method;

    /** @var int */
    private $httpcode;

    /** @var string */
    private $bitrixcode;

    /** @var array */
    private $response;

    public function __construct(
        string $method,
        int $httpcode,
        string $bitrixcode,
        string $message,
        array $response = []
    ) {
        $this->method = $method;
        $this->httpcode = $httpcode;
        $this->bitrixcode = $bitrixcode;
        $this->response = $response;

        parent::__construct($message);
    }

    public function get_method(): string {
        return $this->method;
    }

    public function get_http_code(): int {
        return $this->httpcode;
    }

    public function get_bitrix_code(): string {
        return $this->bitrixcode;
    }

    public function get_response(): array {
        return $this->response;
    }

    public function diagnostic_message(): string {
        $parts = [
            $this->method,
            'HTTP ' . ($this->httpcode ?: 'n/a'),
        ];

        if ($this->bitrixcode !== '') {
            $parts[] = 'Bitrix error=' . $this->bitrixcode;
        }

        $parts[] = $this->getMessage();

        return implode(' | ', $parts);
    }
}
