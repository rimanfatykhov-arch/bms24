<?php
namespace local_kbaai\repository;
defined('MOODLE_INTERNAL') || die();
class assessment_repository {
    public function get($id) { global $DB; return $DB->get_record('local_kbaai_assessment', array('id' => (int)$id), '*', MUST_EXIST); }
    public function latest_for_submission($submissionid) { global $DB; return $DB->get_record_sql('SELECT * FROM {local_kbaai_assessment} WHERE submissionid = ? ORDER BY runno DESC, id DESC', array((int)$submissionid), IGNORE_MULTIPLE); }
    public function published_for_user($instanceid, $userid) { global $DB; return $DB->get_records('local_kbaai_assessment', array('kbaelementid'=>(int)$instanceid,'userid'=>(int)$userid,'publishstatus'=>'published'), 'timepublished DESC, id DESC'); }
    public function audit($assessmentid, $action, $userid = 0, array $details = array()) {
        global $DB; return $DB->insert_record('local_kbaai_audit', (object)array('assessmentid'=>(int)$assessmentid,'actionname'=>(string)$action,'userid'=>(int)$userid,'detailsjson'=>json_encode($details, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'timecreated'=>time()));
    }
}
