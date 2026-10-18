<?php
namespace local_kbaai\dto;
defined('MOODLE_INTERNAL') || die();
class assessment_request {
    public $mode;
    public $language;
    public $learningcontext;
    public $assignmenttext;
    public $teacherinstructions;
    public $referenceanswer;
    public $criteria;
    public $assessmenttarget;
    public $studentanswer;
    public $model;
    public function __construct(array $data) {
        foreach ($data as $key => $value) { $this->{$key} = $value; }
        if (isset($this->assessmenttarget['text'])) { $this->studentanswer = (string)$this->assessmenttarget['text']; }
    }
}
