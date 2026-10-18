<?php
namespace local_kbaai\provider;
defined('MOODLE_INTERNAL') || die();
class provider_exception extends \moodle_exception {
    protected $retryable;
    protected $providerdetails;

    public function __construct($errorcode, $message, $retryable = false, array $details = array()) {
        $this->retryable = (bool)$retryable;
        $this->providerdetails = $details;
        parent::__construct('assessmentfailed', 'local_kbaai', '', $message, $errorcode . ': ' . $message);
    }

    public function is_retryable() {
        return $this->retryable;
    }

    public function get_provider_details() {
        return $this->providerdetails;
    }
}
