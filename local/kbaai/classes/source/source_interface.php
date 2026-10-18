<?php
namespace local_kbaai\source;
defined('MOODLE_INTERNAL') || die();
interface source_interface {
    public function diagnose();
    public function get_submission($submissionid);
    public function list_submissions($instanceid, $limit = 100);
    public function get_target($submission);
    public function get_learning_context($instanceid);
    public function get_max_grade($instanceid);
    public function publish_grade($assessment, $feedback);
}
