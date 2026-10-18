<?php
namespace local_kbaai\dto;
defined('MOODLE_INTERNAL') || die();
class provider_response {
    public $result;
    public $requestid;
    public $raw;
    public $metadata;

    public function __construct(array $result, $requestid = '', $raw = '', array $metadata = array()) {
        $this->result = $result;
        $this->requestid = $requestid;
        $this->raw = $raw;
        $this->metadata = $metadata;
    }
}
