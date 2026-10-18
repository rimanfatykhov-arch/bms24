<?php
namespace local_kbaai\provider;
defined('MOODLE_INTERNAL') || die();
interface provider_interface {
    public function get_id();
    public function get_capabilities();
    public function generate(\local_kbaai\dto\assessment_request $request);
    public function analyse_attachments(array $attachments, $language = 'ru');
    public function generate_supervisor_reply(\local_kbaai\dto\supervisor_request $request);
    public function test_connection();
}
