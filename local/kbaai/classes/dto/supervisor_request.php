<?php
namespace local_kbaai\dto;
defined('MOODLE_INTERNAL') || die();
class supervisor_request {
    public $language = 'ru';
    public $model = '';
    public $learningcontext = array();
    public $conversation = array();
    public $conversationmeta = array();
    public $studentmessage = '';
    public $officialanswer = '';
    public $supervisorinstructions = '';
    public function __construct(array $data) {
        foreach ($data as $key => $value) { $this->{$key} = $value; }
    }
}
