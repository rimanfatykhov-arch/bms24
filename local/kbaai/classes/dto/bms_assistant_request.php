<?php
namespace local_kbaai\dto;

defined('MOODLE_INTERNAL') || die();

/** Normalised BMS Assistant request from a permission-scoped BMS domain context. */
class bms_assistant_request {
    public $operation = '';
    public $domain = '';
    public $field = '';
    public $locale = 'ru';
    public $draftvalue = '';
    public $context = [];
    public $methodology = [];

    public function __construct(array $data) {
        foreach (['operation', 'domain', 'field', 'locale', 'draftvalue'] as $key) {
            if (array_key_exists($key, $data)) {
                $this->$key = (string)$data[$key];
            }
        }
        $this->context = !empty($data['context']) && is_array($data['context']) ? $data['context'] : [];
        $this->methodology = !empty($data['methodology']) && is_array($data['methodology']) ? $data['methodology'] : [];
    }
}
